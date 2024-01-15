<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\MonduApi\Service;

use GuzzleHttp\Exception\GuzzleException;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;

/**
 * Mondu Client Class
 */
class MonduClient
{
    private ConfigService $config;
    private Client $restClient;
    private LoggerInterface $logger;
    private string $key;
    private ?string $salesChannelId;
    private ?bool $sandboxMode = null;

    /**
     * @param ConfigService $configService
     * @param LoggerInterface $logger
     */
    public function __construct(ConfigService $configService, LoggerInterface $logger)
    {
        $this->config = $configService;
        $this->restClient = new Client();
        $this->logger = $logger;

        $this->salesChannelId = null;
    }

    public function setSalesChannelId($salesChannelId = null)
    {
        $this->salesChannelId = $salesChannelId;

        return $this;
    }

    public function createOrder($order)
    {
        $response = $this->sendRequest('orders', 'POST', $order);

        return $response['order'] ?? null;
    }

    public function invoiceOrder($orderUid, $referenceId, $grossAmount, $invoiceUrl, $line_items = [], $discount = 0, $shipping = 0, $currency = 'EUR')
    {
        $body = [
            'currency' => $currency,
            'external_reference_id' => $referenceId,
            'invoice_url' => $invoiceUrl,
            'gross_amount_cents' => $grossAmount,
            'discount_cents' => $discount,
            'shipping_price_cents' => $shipping,
            'line_items' => $line_items
        ];

        $response = $this->sendRequest('orders/'.$orderUid.'/invoices', 'POST', $body);

        return $response['invoice'] ?? null;
    }

    public function getMonduOrder($orderUid): ?array
    {
        $response = $this->sendRequest('orders/' . $orderUid);

        return $response['order'] ?? null;
    }

    public function cancelOrder($orderUid): ?string
    {
        $response = $this->sendRequest('orders/'. $orderUid .'/cancel', 'POST');

        return $response['order']['state'] ?? null;
    }

    public function confirmOrder($orderUuid): ?string
    {
        $response = $this->sendRequest('orders/'. $orderUuid .'/confirm', 'POST');

        return $response['order']['state'] ?? null;
    }

    public function adjustOrder($orderUuid, $body = []): ?array
    {
        return $this->sendRequest( 'orders/' . $orderUuid . '/adjust', 'POST', $body);
    }

    public function updateExternalInfo($orderUuid, $body = []): ?array
    {
        return $this->sendRequest( 'orders/' . $orderUuid . '/update_external_info', 'POST', $body);
    }

    public function cancelInvoice($orderUuid, $invoiceUuid): ?array
    {
        return $this->sendRequest( 'orders/' . $orderUuid . '/invoices/' . $invoiceUuid . '/cancel', 'POST');
    }

    public function cancelCreditNote($invoiceUuid, $creditNoteUuid): ?array
    {
        return $this->sendRequest( 'invoices/' . $invoiceUuid . '/credit_notes/' . $creditNoteUuid . '/cancel', 'POST');
    }

    public function createCreditNote($invoiceUuid, $body = []): ?array
    {
        return $this->sendRequest( 'invoices/' . $invoiceUuid . '/credit_notes', 'POST', $body);
    }

    public function registerWebhook($body = []): ?array
    {
        return $this->sendRequest('webhooks', 'POST', $body);
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

    public function logEvent($body = [])
    {
        try {
            $this->restClient->send(
                $this->getRequestObject('plugin/events', 'POST', array_filter($body))
            );
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::logEvent Failed with an exception message: ' . $e->getMessage());
        }
    }

    public function sendRequest($url, $method = 'GET', $body = []) 
    {
        $request = $this->getRequestObject($url, $method, $body);

        try {
            $response = $this->restClient->send($request);

            return json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $e) {
            $this->logger->alert("MonduClient [{$method} {$url}]: Failed with an exception message: {$e->getMessage()}");

            $eventLog = [
                'response_status' => strval($e->getCode()),
                'origin_event' => $e->getRequest()->getUri()->getPath()
            ];

            if (method_exists($e, 'getRequest')) {
                $eventLog['request_body'] = json_decode($e->getRequest()->getBody()->getContents());
            }

            if (method_exists($e, 'getResponse')) {
                $eventLog['response_body'] = json_decode($e->getResponse()->getBody()->getContents());
            }

            $this->logEvent($eventLog);

            return null;
        }
    }

    private function getRequestObject($url, $method = 'GET', $body = []): Request
    {
        $api = $this->config->setSalesChannelId($this->salesChannelId);

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

    private function getRequestHeaders()
    {
        return [
            'Content-Type' => 'application/json', 
            'Api-Token' => $this->key ?? $this->config->setSalesChannelId($this->salesChannelId)->getApiToken(),
            'x-plugin-version' => $this->config->getPluginVersion(),
            'x-plugin-name' => $this->config->getPluginName()
        ];
    }
}
