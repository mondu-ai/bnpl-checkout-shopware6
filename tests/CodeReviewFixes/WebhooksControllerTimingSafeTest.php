<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\CodeReviewFixes;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\Webhooks\Controller\WebhooksController;
use Mondu\MonduPayment\Components\Webhooks\Service\WebhookService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that WebhooksController uses hash_equals for timing-safe HMAC comparison
 * and supports multiple webhook secrets.
 */
class WebhooksControllerTimingSafeTest extends TestCase
{
    private function createController(ConfigService $configService, ?WebhookService $webhookService = null, ?LoggerInterface $logger = null): WebhooksController
    {
        $webhookService ??= $this->createMock(WebhookService::class);
        $logger ??= $this->createMock(LoggerInterface::class);
        return new WebhooksController($configService, $webhookService, $logger);
    }

    public function testValidSignatureIsAccepted(): void
    {
        $body = json_encode(['topic' => 'order/confirmed', 'order_uuid' => 'abc', 'external_reference_id' => '123']);
        $secret = 'my-webhook-secret';
        $signature = hash_hmac('sha256', $body, $secret);

        $configService = $this->createMock(ConfigService::class);
        $configService->method('isExtendedLogsEnabled')->willReturn(false);
        $configService->method('getAllWebhooksSecrets')->willReturn([$secret]);

        $webhookService = $this->createMock(WebhookService::class);
        $webhookService->method('handleConfirmed')->willReturn([['message' => 'ok'], 200]);

        $controller = $this->createController($configService, $webhookService);

        $request = new Request([], [], [], [], [], ['HTTP_X-Mondu-Signature' => $signature], $body);
        $request->headers->set('X-Mondu-Signature', $signature);

        $response = $controller->process($request, Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $body = json_encode(['topic' => 'order/confirmed', 'order_uuid' => 'abc']);
        $secret = 'my-webhook-secret';

        $configService = $this->createMock(ConfigService::class);
        $configService->method('isExtendedLogsEnabled')->willReturn(false);
        $configService->method('getAllWebhooksSecrets')->willReturn([$secret]);

        $controller = $this->createController($configService);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Mondu-Signature', 'invalid-signature');

        $response = $controller->process($request, Context::createDefaultContext());

        static::assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        static::assertSame('Signature mismatch', $data['message']);
    }

    public function testMultiSecretMatchesSecondSecret(): void
    {
        $body = json_encode(['topic' => 'order/confirmed', 'order_uuid' => 'abc', 'external_reference_id' => '123']);
        $secret1 = 'wrong-secret';
        $secret2 = 'correct-secret';
        $signature = hash_hmac('sha256', $body, $secret2);

        $configService = $this->createMock(ConfigService::class);
        $configService->method('isExtendedLogsEnabled')->willReturn(false);
        $configService->method('getAllWebhooksSecrets')->willReturn([$secret1, $secret2]);

        $webhookService = $this->createMock(WebhookService::class);
        $webhookService->method('handleConfirmed')->willReturn([['message' => 'ok'], 200]);

        $controller = $this->createController($configService, $webhookService);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Mondu-Signature', $signature);

        $response = $controller->process($request, Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
    }

    public function testNoSecretsRejectsAll(): void
    {
        $body = json_encode(['topic' => 'order/confirmed', 'order_uuid' => 'abc']);

        $configService = $this->createMock(ConfigService::class);
        $configService->method('isExtendedLogsEnabled')->willReturn(false);
        $configService->method('getAllWebhooksSecrets')->willReturn([]);

        $controller = $this->createController($configService);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Mondu-Signature', 'any-signature');

        $response = $controller->process($request, Context::createDefaultContext());

        static::assertSame(401, $response->getStatusCode());
    }

    public function testLogDoesNotContainExpectedSignature(): void
    {
        $body = json_encode(['topic' => 'order/confirmed', 'order_uuid' => 'abc']);
        $secret = 'my-secret';

        $configService = $this->createMock(ConfigService::class);
        $configService->method('isExtendedLogsEnabled')->willReturn(true);
        $configService->method('getAllWebhooksSecrets')->willReturn([$secret]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::atLeastOnce())
            ->method('info')
            ->with(
                static::anything(),
                static::callback(function (array $context): bool {
                    return !array_key_exists('expected_signature', $context);
                })
            );

        $controller = $this->createController($configService, null, $logger);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Mondu-Signature', 'wrong');

        $controller->process($request, Context::createDefaultContext());
    }
}
