<?php
declare(strict_types=1);

namespace Mondu\MonduPayment\Components\MonduApi\Service;

use GuzzleHttp\Exception\GuzzleException;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

class MonduClient {
    private ConfigService $config;
    private $restClient;

    public function __construct(ConfigService $configService) {
        $this->config = $configService;
        $this->restClient = new Client();
    }

    static function getMonduClientInstance($config) {
        return new MonduClient($config);
    }

    public function createOrder($order) {
        $body = json_encode($order);
        $request = $this->getRequestObject('orders', 'POST', $body);
        try {
            $response = $this->restClient->send($request);
            $body = json_decode($response->getBody()->getContents(), true);
            return $body['order'];

        } catch (GuzzleException $e) {
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
            //not implemented
            return null;
        }
    }

    public function invoiceOrder($orderUid, $referenceId, $grossAmount, $invoiceUrl) {
        $body = json_encode([
            'external_reference_id' => $referenceId,
            'invoice_url' => $invoiceUrl,
            'gross_amount_cents' => $grossAmount
        ]);

        $request = $this->getRequestObject('orders/'.$orderUid.'/invoices','POST', $body);

        $response = $this->restClient->send($request);

        $responseBody = json_decode($response->getBody()->getContents(), true);

        return @$responseBody['invoice'];
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