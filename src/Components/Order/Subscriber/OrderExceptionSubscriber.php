<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class OrderExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $router
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
        
        // LOG ALL EXCEPTIONS to debug
        $this->logger->info('mondu.DEBUG: Exception caught in subscriber', [
            'message' => $exception->getMessage(),
            'class' => get_class($exception),
            'uri' => $request->getRequestUri(),
            'route' => $request->attributes->get('_route'),
        ]);
        
        // Check if this is a "cannot be edited" or "was cancelled" error
        if (stripos($exception->getMessage(), 'cannot be edited') !== false || 
            stripos($exception->getMessage(), 'was cancelled') !== false ||
            stripos($exception->getMessage(), 'cancelled') !== false) {
            
            $requestUri = $request->getRequestUri();
            
            $this->logger->info('mondu.INFO: Caught "cannot be edited" exception', [
                'error' => $exception->getMessage(),
                'uri' => $requestUri,
                'class' => get_class($exception),
                'route' => $request->attributes->get('_route')
            ]);
            
            // Check if this is during payment finalization or order edit
            if (stripos($requestUri, '/payment/finalize-transaction') !== false ||
                stripos($requestUri, '/checkout/finalize') !== false ||
                stripos($requestUri, '/account/order/edit') !== false ||
                stripos($request->attributes->get('_route', ''), 'payment') !== false ||
                stripos($request->attributes->get('_route', ''), 'order') !== false) {
                
                $this->logger->info('mondu.INFO: Redirecting user to order page instead of showing error', [
                    'uri' => $requestUri
                ]);
                
                // Extract order ID from exception message if possible
                preg_match('/"([^"]+)"/', $exception->getMessage(), $matches);
                $orderNumber = $matches[1] ?? null;
                
                // Redirect to account orders page with flash message
                try {
                    $redirectUrl = $this->router->generate('frontend.account.order.page', [], UrlGeneratorInterface::ABSOLUTE_PATH);
                    
                    $response = new RedirectResponse($redirectUrl);
                    
                    // Add flash message to session if available
                    $session = $request->hasSession() ? $request->getSession() : null;
                    if ($session) {
                        $message = $orderNumber 
                            ? "Payment for order {$orderNumber} was declined. The order has been automatically cancelled."
                            : "Payment was declined. The order has been automatically cancelled.";
                        $session->getFlashBag()->add('info', $message);
                    }
                    
                    $event->setResponse($response);
                    
                    $this->logger->info('mondu.INFO: Successfully set redirect response', [
                        'redirectUrl' => $redirectUrl
                    ]);
                } catch (\Exception $e) {
                    // If redirect fails, at least log it
                    $this->logger->error('mondu.CRITICAL: Failed to redirect user', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }
}

