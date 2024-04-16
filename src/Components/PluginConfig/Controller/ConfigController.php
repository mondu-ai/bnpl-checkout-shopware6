<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PluginConfig\Controller;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ConfigController extends AbstractController
{
    public function __construct(
        private readonly MonduClient $monduClient
    ) {}

    #[Route(path: '/api/mondu/config/test', name: 'mondu-payment.config.test', methods: ['POST'])]
    public function test(Request $request, Context $context): Response
    {
        try {
            $data = json_decode($request->getContent());

            if (isset($data->apiCredentials)) {
                $response = $this->monduClient->getWebhooksSecret($data->apiCredentials, $data->sandboxMode);

                if ($response != null) {
                    return new Response(json_encode(['status' => 'ok', 'error' => '0']), Response::HTTP_OK);
                }

                return new Response(json_encode(['status' => 'request_failed', 'error' => '1' ]), Response::HTTP_BAD_REQUEST);
            }
            
            return new Response(json_encode(['status' => 'request_failed', 'error' => '2' ]), Response::HTTP_BAD_REQUEST);
        
        } catch (\Exception) {
            return new Response(json_encode(['status' => 'error', 'error' => '3' ]), Response::HTTP_BAD_REQUEST);
        }
    }
}
