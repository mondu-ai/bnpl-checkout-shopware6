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
use Psr\Log\LoggerInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhooksController extends StorefrontController
{
    private ConfigService $configService;
    private WebhookService $webhookService;
    private LoggerInterface $logger;

    public function __construct(
        ConfigService $configService,
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        $this->configService = $configService;
        $this->webhookService = $webhookService;
        $this->logger = $logger;
    }

    #[Route(path: '/mondu/webhooks', name: 'mondu-payment.webhooks', methods: ['POST'])]
    public function process(Request $request, Context $context): Response
    {
        $content = $request->getContent();
        $headers = $request->headers;
        $params = json_decode($content, true);
        
        // Log incoming webhook (only if extended logs enabled)
        if ($this->configService->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: Incoming webhook received', [
                'topic' => $params['topic'] ?? 'unknown',
                'order_uuid' => $params['order_uuid'] ?? null,
                'external_reference_id' => $params['external_reference_id'] ?? null,
                'order_state' => $params['order_state'] ?? null
            ]);
        }

        $signature = hash_hmac('sha256', $content, $this->configService->getWebhooksSecret());
        if ($signature !== $headers->get('X-Mondu-Signature')) {
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Webhook signature mismatch', [
                    'topic' => $params['topic'] ?? 'unknown',
                    'expected_signature' => $signature,
                    'received_signature' => $headers->get('X-Mondu-Signature')
                ]);
            }
            
            return new Response(
                json_encode([
                    'message' => 'Signature mismatch',
                    'code' => 401
                ]),
                Response::HTTP_UNAUTHORIZED,
            );
        }

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
            case 'order':
                // Generic 'order' topic - dispatch based on order_state
                $orderState = $params['order_state'] ?? null;
                
                switch ($orderState) {
                    case 'confirmed':
                        [$resBody, $resStatus] = $this->webhookService->handleConfirmed($params, $context);
                        break;
                    case 'pending':
                        [$resBody, $resStatus] = $this->webhookService->handlePending($params, $context);
                        break;
                    case 'declined':
                    case 'canceled':
                        [$resBody, $resStatus] = $this->webhookService->handleDeclinedOrCanceled($params, $context);
                        break;
                    default:
                        if ($this->configService->isExtendedLogsEnabled()) {
                            $this->logger->info('mondu.INFO: Unknown order_state for order webhook', [
                                'topic' => $topic,
                                'order_state' => $orderState,
                                'order_uuid' => $params['order_uuid'] ?? null
                            ]);
                        }
                        $resBody = ['message' => 'Unknown order_state', 'code' => 200];
                        $resStatus = 200;
                }
                break;
            default:
                // Log unregistered webhook topics only if extended logs enabled
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Unregistered webhook topic', [
                        'topic' => $topic,
                        'order_uuid' => $params['order_uuid'] ?? null
                    ]);
                }
                $resBody = ['message' => 'Unregistered topic', 'code' => 200];
                $resStatus = 200;
        }

        // Log webhook processing result (only if extended logs enabled)
        if ($this->configService->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: Webhook processed', [
                'topic' => $topic,
                'response_status' => $resStatus,
                'response_body' => $resBody
            ]);
        }

        return new Response(
            json_encode($resBody),
            $resStatus,
            ['content-type' => 'application/json']
        );
    }
}
