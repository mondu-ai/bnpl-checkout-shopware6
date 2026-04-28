<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class OrderExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $router,
        private readonly ConfigService $configService,
        private readonly OrderTransactionStateHandler $transactionStateHandler
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 100],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Skip Admin API requests — this subscriber only handles the frontend
        // checkout/payment flow (cannot be edited / was cancelled / Illegal transition).
        // Admin API exceptions (e.g. MissingPrivilegeException on /api/search/*) are
        // unrelated to Mondu and would only produce noise in the log.
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Skip webhook route-not-found noise (Mondu sends to all registered URLs including inactive sales channels)
        if (str_contains($request->getRequestUri(), '/mondu/webhooks')) {
            return;
        }

        // LOG ALL EXCEPTIONS to debug
        if ($this->configService->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.DEBUG: Exception caught in subscriber', [
                'message' => $exception->getMessage(),
                'class' => get_class($exception),
                'uri' => $request->getRequestUri(),
                'route' => $request->attributes->get('_route'),
            ]);
        }
        
        $requestUri = $request->getRequestUri();
        $route = $request->attributes->get('_route', '');

        // SW6.6 PaymentProcessor auto-cancels the transaction after catching customerCanceled.
        // This block runs independently of the exception message — we detect declined by query param.
        // After PaymentProcessor's cancel(), we correct the state to failed via reopen→process→fail.
        if ($request->query->get('payment') === 'declined' &&
            (stripos($requestUri, '/payment/finalize-transaction') !== false || $route === 'payment.finalize.transaction')) {
            $transactionId = $this->extractTransactionIdFromToken($request);
            if ($transactionId !== null) {
                try {
                    $context = Context::createDefaultContext();
                    $this->transactionStateHandler->reopen($transactionId, $context);
                    $this->transactionStateHandler->process($transactionId, $context);
                    $this->transactionStateHandler->fail($transactionId, $context);

                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Corrected declined transaction state to failed', [
                            'transactionId' => $transactionId
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('mondu.ERROR: Failed to correct declined transaction state', [
                        'error' => $e->getMessage(),
                        'transactionId' => $transactionId ?? 'unknown'
                    ]);
                }
            }
        }

        // Handle cancellation errors (when order was already cancelled by webhook)
        if (stripos($exception->getMessage(), 'cannot be edited') !== false ||
            stripos($exception->getMessage(), 'was cancelled') !== false ||
            stripos($exception->getMessage(), 'Illegal transition') !== false) {

            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Caught "cannot be edited" or "was cancelled" exception', [
                    'error' => $exception->getMessage(),
                    'uri' => $requestUri,
                    'class' => get_class($exception),
                    'route' => $route
                ]);
            }

            if (stripos($requestUri, '/payment/finalize-transaction') !== false ||
                stripos($requestUri, '/checkout/finalize') !== false ||
                stripos($requestUri, '/account/order/edit') !== false ||
                stripos($route, 'payment') !== false ||
                stripos($route, 'order') !== false) {

                // For the payment finalize route, redirect to the errorUrl from the JWT token
                // so the buyer lands on the order edit page (not the order list).
                // PaymentProcessor internally tries to cancel an already-cancelled/failed transaction,
                // which throws IllegalTransitionException — we handle it gracefully here.
                if (stripos($requestUri, '/payment/finalize-transaction') !== false ||
                    $route === 'payment.finalize.transaction') {

                    $errorUrl = $this->extractErrorUrlFromToken($request);
                    if ($errorUrl !== null) {
                        $separator = parse_url($errorUrl, PHP_URL_QUERY) ? '&' : '?';
                        $redirectUrl = $errorUrl . $separator . 'error-code=CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT';

                        if ($this->configService->isExtendedLogsEnabled()) {
                            $this->logger->info('mondu.INFO: Redirecting to order edit page (from JWT errorUrl)', [
                                'redirectUrl' => $redirectUrl
                            ]);
                        }

                        $event->setResponse(new RedirectResponse($redirectUrl));
                        return;
                    }
                }

                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Redirecting user to order page instead of showing error', [
                        'uri' => $requestUri
                    ]);
                }

                try {
                    $redirectUrl = $this->router->generate('frontend.account.order.page', [], UrlGeneratorInterface::ABSOLUTE_PATH);

                    $event->setResponse(new RedirectResponse($redirectUrl));

                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Successfully set redirect response', [
                            'redirectUrl' => $redirectUrl
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('mondu.CRITICAL: Failed to redirect user', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    private function extractTransactionIdFromToken(Request $request): ?string
    {
        $token = $request->query->get('_sw_payment_token');
        if (!\is_string($token)) {
            return null;
        }

        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            return null;
        }

        try {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            return isset($payload['sub']) && \is_string($payload['sub']) ? $payload['sub'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractErrorUrlFromToken(Request $request): ?string
    {
        $token = $request->query->get('_sw_payment_token');
        if (!\is_string($token)) {
            return null;
        }

        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            return null;
        }

        try {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            return isset($payload['eul']) && \is_string($payload['eul']) ? $payload['eul'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

