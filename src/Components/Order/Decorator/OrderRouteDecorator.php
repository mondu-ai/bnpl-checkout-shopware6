<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Decorator;

use Mondu\MonduPayment\Components\Order\Service\PaymentChangeLock;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marks orders Mondu has not decided on as not payment-changeable, see PaymentChangeLock.
 * The account's edit order page then hides the payment choice and says why.
 */
class OrderRouteDecorator extends AbstractOrderRoute
{
    public function __construct(
        private readonly AbstractOrderRoute $inner,
        private readonly PaymentChangeLock $paymentChangeLock
    ) {}

    public function getDecorated(): AbstractOrderRoute
    {
        return $this->inner;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): OrderRouteResponse
    {
        $response = $this->inner->load($request, $context, $criteria);

        $lockedIds = $this->paymentChangeLock->lockedOrderIds(
            $response->getOrders()->getIds(),
            $context->getContext()
        );

        if ($lockedIds !== []) {
            $response->addPaymentChangeable(array_fill_keys($lockedIds, false));
        }

        return $response;
    }
}
