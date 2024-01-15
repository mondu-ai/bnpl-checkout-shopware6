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

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
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

    /**
     * @Route("/mondu/webhooks", name="mondu-payment.webhooks", defaults={"csrf_protected"=false}, methods={"POST"})
     * @throws \Exception
     */
    public function process(Request $request, Context $context): Response
    {
        $content = $request->getContent();
        $headers = $request->headers;

        $signature = hash_hmac('sha256', $content, $this->configService->getWebhooksSecret());
        if ($signature !== $headers->get('X-Mondu-Signature')) {
            throw new \Exception('Signature mismatch');
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
            case 'order/canceled':
            case 'order/declined':
                [$resBody, $resStatus] = $this->webhookService->handleDeclinedOrCanceled($params, $context);
                break;
            default:
                throw new \Exception('Unregistered topic');
        }

        return new Response(
            json_encode($resBody),
            $resStatus,
            ['content-type' => 'application/json']
        );
    }
}
