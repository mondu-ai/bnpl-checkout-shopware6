<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Controller;

use Shopware\Core\Framework\Context;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\Webhooks\Service\WebhookService;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhooksController extends StorefrontController
{
    private ConfigService $configService;
    private WebhookService $webhookService;

    public function __construct(
        ConfigService $configService,
        WebhookService $webhookService
    ) {
        $this->configService = $configService;
        $this->webhookService = $webhookService;
    }

    #[Route(path: '/mondu/webhooks', name: 'mondu-payment.webhooks', methods: ['POST'])]
    public function process(Request $request, Context $context): Response
    {
        $content = $request->getContent();
        $headers = $request->headers;

        $signature = hash_hmac('sha256', $content, $this->configService->getWebhooksSecret());
        if ($signature !== $headers->get('X-Mondu-Signature')) {
            return new Response(
                json_encode([
                    'message' => 'Signature mismatch',
                    'code' => 401
                ]),
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $params = json_decode($content, true);
        $topic = $params['topic'];

        switch ($topic) {
            case 'order/confirmed':
                [$resBody, $resStatus] = $this->webhookService->handleConfirmed($params, $context);
                break;
            case 'order/pending':
                [$resBody, $resStatus] = $this->webhookService->handlePending($params, $context);
                break;
            case 'order/declined':
                [$resBody, $resStatus] = $this->webhookService->handleDeclinedOrCanceled($params, $context);
                break;
            default:
                $resBody = ['message' => 'Unregistered topic', 'code' => 200];
                $resStatus = 200;
        }

        return new Response(
            json_encode($resBody),
            $resStatus,
            ['content-type' => 'application/json']
        );
    }
}
