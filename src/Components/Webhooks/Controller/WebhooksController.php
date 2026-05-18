<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
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

        if ($this->configService->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: Incoming webhook received', [
                'topic' => $params['topic'] ?? 'unknown',
                'order_uuid' => $params['order_uuid'] ?? null,
                'external_reference_id' => $params['external_reference_id'] ?? null,
                'order_state' => $params['order_state'] ?? null
            ]);
        }

        $receivedSignature = (string) $headers->get('X-Mondu-Signature');

        // In a multi–sales-channel setup each channel may have its own webhook
        // registration with a distinct secret (Mondu returns a new secret per
        // registered webhook URL). Shopware stores those secrets under different
        // config scopes. We try each known secret until one matches — otherwise we
        // cannot tell which sales channel the incoming event belongs to.
        $secrets = $this->configService->getAllWebhooksSecrets();
        $matched = false;
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $content, $secret);
            if (hash_equals($expected, $receivedSignature)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Webhook signature mismatch', [
                    'topic' => $params['topic'] ?? 'unknown',
                    'candidate_secrets_tried' => count($secrets),
                    'received_signature' => $receivedSignature
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

        // Shopware's storefront routing resolves each incoming request to a sales
        // channel based on the matching sales_channel_domain.url and stores its id
        // in the request attributes. We pre-seed WebhookService with that value as
        // a fallback — handlers then override it via resolveSalesChannelIdFromOrder()
        // once they find the owning order. If neither works, scope stays null (default).
        $scFromUrl = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        if (is_string($scFromUrl) && $scFromUrl !== '') {
            $this->webhookService->setSalesChannelId($scFromUrl);
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
            case 'order/canceled':
            case 'order/cancelled':
                [$resBody, $resStatus] = $this->webhookService->handleDeclinedOrCanceled($params, $context);
                break;
            case 'order':
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
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Unregistered webhook topic', [
                        'topic' => $topic,
                        'order_uuid' => $params['order_uuid'] ?? null
                    ]);
                }
                $resBody = ['message' => 'Unregistered topic', 'code' => 200];
                $resStatus = 200;
        }

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
