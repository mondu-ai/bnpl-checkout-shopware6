<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PluginConfig\Controller;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ConfigController extends AbstractController
{
    public function __construct(
        private readonly MonduClient $monduClient
    ) {}

    #[Route(path: '/mondu/config/test', name: 'mondu-payment.config.test', methods: ['POST'])]
    public function test(Request $request, Context $context): Response
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return new Response(json_encode(['status' => 'invalid_json', 'error' => '4']), Response::HTTP_BAD_REQUEST);
            }

            if (isset($data['apiCredentials']) && !empty($data['apiCredentials'])) {
                $apiCredentials = $data['apiCredentials'];
                $sandboxMode = isset($data['sandboxMode']) ? (bool)$data['sandboxMode'] : false;

                try {
                    $response = $this->monduClient->getWebhooksSecret($apiCredentials, $sandboxMode);
                    
                    if ($response !== null) {
                        return new Response(json_encode([
                            'status' => 'ok', 
                            'error' => '0', 
                            'message' => 'API credentials are valid',
                            'sandbox' => $sandboxMode
                        ]), Response::HTTP_OK);
                    } else {
                        return new Response(json_encode([
                            'status' => 'invalid_credentials', 
                            'error' => '1', 
                            'message' => 'API credentials are invalid or API is unreachable'
                        ]), Response::HTTP_BAD_REQUEST);
                    }
                } catch (\Exception $e) {
                    return new Response(json_encode([
                        'status' => 'api_error', 
                        'error' => '5', 
                        'message' => 'Error validating credentials: ' . $e->getMessage()
                    ]), Response::HTTP_BAD_REQUEST);
                }
            }
            
            return new Response(json_encode(['status' => 'missing_credentials', 'error' => '2']), Response::HTTP_BAD_REQUEST);
        
        } catch (\Exception $e) {
            return new Response(json_encode(['status' => 'exception', 'error' => '3', 'message' => $e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
