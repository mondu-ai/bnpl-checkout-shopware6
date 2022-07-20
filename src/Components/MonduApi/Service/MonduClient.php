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
    private ConfigService $config;
    private $restClient;
    private LoggerInterface $logger;

    public function __construct(ConfigService $configService, LoggerInterface $logger)
    {
        $this->config = $configService;
        $this->restClient = new Client();
        $this->logger = $logger;
    }

    public static function getMonduClientInstance($config)
    {
        return new MonduClient($config);
    }

    public function createOrder($order)
    {
        $body = json_encode($order);
        $request = $this->getRequestObject('orders', 'POST', $body);
        try {
            $response = $this->restClient->send($request);
            $body = json_decode($response->getBody()->getContents(), true);
            return $body['order'];
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::createOrder Failed with an exception message: ' . $e->getMessage());
            return null;
        }
    }

    public function getMonduOrder($orderUid): ?array
    {
        $request = $this->getRequestObject('orders/'.$orderUid);
        try {
            $response = $this->restClient->send($request);
            $body = json_decode($response->getBody()->getContents(), true);
            return @$body['order'];
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::getMonduOrder Failed with an exception message: ' . $e->getMessage());
            return null;
        }
    }

    public function cancelOrder($orderUid): ?string
    {
        $request = $this->getRequestObject('orders/'. $orderUid.'/cancel', 'POST');
        try {
            $response = $this->restClient->send($request);
            $body = json_decode($response->getBody()->getContents(), true);
            return @$body['order']['state'];
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::cancelOrder Failed with an exception message: ' . $e->getMessage());
            return null;
        }
    }

    public function adjustOrder($orderUuid, $body = []): ?array
    {
        $request = $this->getRequestObject('orders/'. $orderUuid.'/adjust', 'POST', json_encode($body));
        try {
            $response = $this->restClient->send($request);
            $body = json_decode($response->getBody()->getContents(), true);
            return @$body;
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::adjustOrder Failed with an exception message: ' . $e->getMessage());
            return null;
        }
    }

    public function cancelInvoice($orderUuid, $invoiceUuid): ?array
    {
        $request = $this->getRequestObject('orders/'. $orderUuid.'/invoices/' . $invoiceUuid . '/cancel', 'POST');
        try {
            $response = $this->restClient->send($request);
            $body = json_decode($response->getBody()->getContents(), true);
            return @$body;
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::cancelInvoice Failed with an exception message: ' . $e->getMessage());
            return null;
        }
    }

    public function createCreditNote($invoiceUuid, $body = []): ?array
    {
        $request = $this->getRequestObject('invoices/' . $invoiceUuid . '/credit_notes', 'POST', json_encode($body));
        try {
            $response = $this->restClient->send($request);
            $body = json_decode($response->getBody()->getContents(), true);
            return @$body;
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::createCreditNote Failed with an exception message: ' . $e->getMessage());
            return null;
        }
    }

    public function invoiceOrder($orderUid, $referenceId, $grossAmount, $invoiceUrl, $line_items = [], $discount = 0, $shipping = 0)
    {
        try {
            $body = json_encode([
                'external_reference_id' => $referenceId,
                'invoice_url' => $invoiceUrl,
                'gross_amount_cents' => $grossAmount,
                'discount_cents' => $discount,
                'shipping_price_cents' => $shipping,
                'line_items' => $line_items
            ]);

            $request = $this->getRequestObject('orders/'.$orderUid.'/invoices', 'POST', $body);

            $response = $this->restClient->send($request);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            return @$responseBody['invoice'];
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::invoiceOrder Failed with an exception message: ' . $e->getMessage());
            return null;
        }
    }

    public function registerWebhook($body = []): ?array
    {
        $request = $this->getRequestObject('webhooks', 'POST', json_encode($body));
        try {
            $response = $this->restClient->send($request);
            $body = json_decode($response->getBody()->getContents(), true);
            return @$body;
        } catch (GuzzleException $e) {
            $this->logger->alert('MonduClient::registerWebhooks Failed with an exception message: ' . $e->getMessage());
            return null;
        }
    }

    private function getRequestObject($url, $method = 'GET', $body = ''): Request
    {
        return new Request(
            $method,
            $this->config->getApiUrl($url),
            ['Content-Type' => 'application/json', 'Api-Token' => $this->config->getApiToken()],
            $body
        );
    }
}
