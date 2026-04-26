<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\MonduApi\Service;

use GuzzleHttp\Exception\GuzzleException;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;

class MonduClient
{
    /**
     * @var Client
     */
    private Client $restClient;

    /**
     * @var string|null
     */
    private ?string $key = null;

    /**
     * @var string|null
     */
    private ?string $salesChannelId;

    /**
     * @var bool|null
     */
    private ?bool $sandboxMode = null;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly LoggerInterface $logger
    ) {
        $this->restClient = new Client();
        $this->salesChannelId = null;
    }

    public function setSalesChannelId($salesChannelId = null): static
    {
        $this->salesChannelId = $salesChannelId;

        return $this;
    }

    public function createOrder($order)
    {
        $response = $this->sendRequest('orders', 'POST', $order);

        return $response['order'] ?? null;
    }

    public function invoiceOrder($orderUid, $body)
    {
        $response = $this->sendRequest('orders/'.$orderUid.'/invoices', 'POST', $body);

        if (is_array($response) && isset($response['status'])) {
            return $response;
        }

        return $response['invoice'] ?? null;
    }

    public function getMonduOrder($orderUid): ?array
    {
        $response = $this->sendRequest('orders/' . $orderUid);

        return $response['order'] ?? null;
    }

    public function cancelOrder($orderUid): ?string
    {
        $request = $this->getRequestObject("orders/". $orderUid ."/cancel", "POST");

        try {
            $response = $this->restClient->send($request);
            $result = json_decode($response->getBody()->getContents(), true);
            return $result["order"]["state"] ?? null;
        } catch (GuzzleException $e) {
            return null;
        }
    }

    public function confirmOrder($orderUuid, $data): ?string
    {
        $response = $this->sendRequest('orders/'. $orderUuid .'/confirm', 'POST', $data);
        
        $state = $response['state'] ?? $response['order']['state'] ?? null;
        
        if ($this->configService->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: confirmOrder full API response', [
                'order_uuid' => $orderUuid,
                'request_data' => $data,
                'response' => $response,
                'state_from_root' => $response['state'] ?? null,
                'state_from_order' => $response['order']['state'] ?? null,
                'final_state' => $state
            ]);
        }

        return $state;
    }

    public function adjustOrder($orderUuid, $body = []): ?array
    {
        return $this->sendRequest('orders/'. $orderUuid .'/adjust', 'POST', $body);
    }

    public function updateExternalInfo($orderUuid, $body = []): ?array
    {
        return $this->sendRequest('orders/'. $orderUuid.'/update_external_info', 'POST', $body);
    }

    public function cancelInvoice($orderUuid, $invoiceUuid): ?array
    {
        return $this->sendRequest('orders/'. $orderUuid.'/invoices/' . $invoiceUuid . '/cancel', 'POST');
    }

    public function cancelCreditNote($invoiceUuid, $creditNoteUuid): ?array
    {
        $url = 'invoices/' . $invoiceUuid . '/credit_notes/' . $creditNoteUuid . '/cancel';
        $request = $this->getRequestObject($url, 'POST');

        try {
            $response = $this->restClient->send($request);
            $decoded = json_decode($response->getBody()->getContents(), true);

            return is_array($decoded) ? $decoded : ['status' => 'ok'];
        } catch (GuzzleException $e) {
            $responseBody = null;

            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            }

            if ($e->getCode() == 422 && isset($responseBody['errors'])) {
                foreach ($responseBody['errors'] as $error) {
                    $details = $error['details'] ?? '';
                    $code = $error['code'] ?? '';
                    // Mondu phrasings observed in the wild:
                    //   "already cancelled" / "already_cancelled"
                    //   "can not be canceled" / "cannot be cancelled"
                    //      (returned e.g. when the parent invoice itself is cancelled,
                    //       so the credit note is effectively no longer cancellable)
                    if (stripos($details, 'already cancel') !== false
                        || stripos($code, 'already_cancel') !== false
                        || stripos($details, 'already_cancel') !== false
                        || stripos($details, 'can not be cancel') !== false
                        || stripos($details, 'cannot be cancel') !== false) {
                        if ($this->configService->isExtendedLogsEnabled()) {
                            $this->logger->info("mondu.INFO: MonduClient [POST {$url}]: credit note already cancelled - {$details}");
                        }
                        return ['status' => 'already_cancelled', 'message' => $details];
                    }
                }
            }

            if ($e->getCode() == 404) {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info("mondu.INFO: MonduClient [POST {$url}]: 404 not found at Mondu");
                }
                return ['status' => 'not_found', 'message' => $responseBody['message'] ?? 'not_found'];
            }

            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->warning("mondu.WARNING: MonduClient [POST {$url}]: Failed with an exception message: {$e->getMessage()}");
            }

            $eventLog = [
                'response_status' => strval($e->getCode()),
                'origin_event' => $e->getRequest()->getUri()->getPath()
            ];

            if (method_exists($e, 'getRequest')) {
                $eventLog['request_body'] = json_decode($e->getRequest()->getBody()->getContents());
            }

            if ($responseBody) {
                $eventLog['response_body'] = $responseBody;
            }

            $this->logEvent($eventLog);

            return null;
        }
    }

    public function createCreditNote($invoiceUuid, $body = []): ?array
    {
        $url = 'invoices/' . $invoiceUuid . '/credit_notes';
        $request = $this->getRequestObject($url, 'POST', $body);

        try {
            $response = $this->restClient->send($request);
            $decoded = json_decode($response->getBody()->getContents(), true);

            return is_array($decoded) ? $decoded : null;
        } catch (GuzzleException $e) {
            $responseBody = null;

            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            }

            // Mondu refuses credit notes on an invoice that is already cancelled:
            //   details: "can not create credit note for canceled invoice that has not been paid"
            if ($e->getCode() == 422 && isset($responseBody['errors'])) {
                foreach ($responseBody['errors'] as $error) {
                    $details = $error['details'] ?? '';
                    if (stripos($details, 'canceled invoice') !== false
                        || stripos($details, 'cancelled invoice') !== false) {
                        if ($this->configService->isExtendedLogsEnabled()) {
                            $this->logger->info("mondu.INFO: MonduClient [POST {$url}]: parent invoice cancelled - {$details}");
                        }
                        return ['status' => 'invoice_cancelled', 'message' => $details];
                    }
                }
            }

            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->warning("mondu.WARNING: MonduClient [POST {$url}]: Failed with an exception message: {$e->getMessage()}");
            }

            $eventLog = [
                'response_status' => strval($e->getCode()),
                'origin_event' => $e->getRequest()->getUri()->getPath()
            ];

            if (method_exists($e, 'getRequest')) {
                $eventLog['request_body'] = json_decode($e->getRequest()->getBody()->getContents());
            }

            if ($responseBody) {
                $eventLog['response_body'] = $responseBody;
            }

            $this->logEvent($eventLog);

            return null;
        }
    }

    public function registerWebhook($body = []): ?array
    {
        return $this->sendRequest('webhooks', 'POST', $body, true);
    }

    public function getWebhooksSecret($key, $sandboxMode = null): ?array
    {
        $this->key = $key;
        $this->sandboxMode = $sandboxMode;

        return $this->sendRequest('webhooks/keys');
    }

    public function getPaymentMethods()
    {
        return $this->sendRequest('payment_methods');
    }

    public function logEvent($body = []): void
    {
        try {
            $this->restClient->send(
                $this->getRequestObject('plugin/events', 'POST', array_filter($body))
            );
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::logEvent Failed with an exception message: ' . $e->getMessage());
        }
    }

    public function sendRequest($url, $method = 'GET', $body = [], $allowAlreadySubscribed = false)
    {
        $request = $this->getRequestObject($url, $method, $body);

        try {
            $response = $this->restClient->send($request);

            return json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $e) {
            $responseBody = null;

            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                
                if ($allowAlreadySubscribed && 
                    $e->getCode() == 422 && 
                    isset($responseBody['errors'][0]['details']) && 
                    strpos($responseBody['errors'][0]['details'], 'already subscribed') !== false) {
                    
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info("mondu.INFO: MonduClient [{$method} {$url}]: Webhook already registered - " . $responseBody['errors'][0]['details']);
                    }
                    return ['status' => 'already_registered', 'message' => $responseBody['errors'][0]['details']];
                }
                
                if ($e->getCode() == 422 &&
                    isset($responseBody['errors'][0]['details']) &&
                    strpos($responseBody['errors'][0]['details'], 'must be unique') !== false) {

                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info("mondu.INFO: MonduClient [{$method} {$url}]: Invoice already exists, returning special status - " . $responseBody['errors'][0]['details']);
                    }

                    return ['status' => 'already_exists', 'message' => $responseBody['errors'][0]['details']];
                }
            }

            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->warning("mondu.WARNING: MonduClient [{$method} {$url}]: Failed with an exception message: {$e->getMessage()}");
            }

            $eventLog = [
                'response_status' => strval($e->getCode()),
                'origin_event' => $e->getRequest()->getUri()->getPath()
            ];

            if (method_exists($e, 'getRequest')) {
                $eventLog['request_body'] = json_decode($e->getRequest()->getBody()->getContents());
            }

            if ($responseBody) {
                $eventLog['response_body'] = $responseBody;
            }

            $this->logEvent($eventLog);

            return null;
        }
    }

    private function getRequestObject($url, $method = 'GET', $body = []): Request
    {
        $api = $this->configService->setSalesChannelId($this->salesChannelId);

        if (!is_null($this->sandboxMode)) {
            $api = $api->setOverrideSandbox($this->sandboxMode);
        }

        return new Request(
            $method,
            $api->getApiUrl($url),
            $this->getRequestHeaders(),
            empty($body) ? null : json_encode($body)
        );
    }

    private function getRequestHeaders(): array
    {
        return [
            'Content-Type' => 'application/json', 
            'Api-Token' => $this->key ?? $this->configService->setSalesChannelId($this->salesChannelId)->getApiToken(),
            'x-plugin-version' => $this->configService->getPluginVersion(),
            'x-plugin-name' => $this->configService->getPluginName()
        ];
    }
}
