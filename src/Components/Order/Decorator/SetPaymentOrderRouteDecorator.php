<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Decorator;

use Mondu\MonduPayment\Components\Order\Service\PaymentChangeLock;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractSetPaymentOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\SetPaymentOrderRouteResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Refuses a payment method change while Mondu has not decided on the order, see PaymentChangeLock.
 * Covers the storefront account and every Store API client.
 */
class SetPaymentOrderRouteDecorator extends AbstractSetPaymentOrderRoute
{
    public function __construct(
        private readonly AbstractSetPaymentOrderRoute $inner,
        private readonly PaymentChangeLock $paymentChangeLock
    ) {}

    public function getDecorated(): AbstractSetPaymentOrderRoute
    {
        return $this->inner;
    }

    public function setPayment(Request $request, SalesChannelContext $context): SetPaymentOrderRouteResponse
    {
        $orderId = (string) $request->request->get('orderId', '');

        if ($orderId !== '' && $this->paymentChangeLock->isLocked($orderId, $context->getContext())) {
            throw OrderException::paymentMethodNotChangeable();
        }

        return $this->inner->setPayment($request, $context);
    }
}
