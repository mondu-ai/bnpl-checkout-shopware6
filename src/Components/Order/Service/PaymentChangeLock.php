<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Service;

use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderStates;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Decides whether the buyer may change the payment method of an order Mondu has not decided on yet.
 *
 * Shopware lets the buyer switch the payment method while the transaction is open or unconfirmed.
 * For a Mondu order that would leave Mondu assessing, or waiting for the buyer to confirm, an
 * order the shop then pays differently. Locked are:
 * - an order Mondu is reviewing (transaction unconfirmed, or one of the states of orders created
 *   in the admin);
 * - an order created in the admin before Mondu's first answer arrived (transaction still open),
 *   unless Mondu declined or cancelled it already.
 *
 * A failed or cancelled payment stays changeable, so the buyer can pay a declined order or an
 * abandoned Mondu checkout another way.
 */
class PaymentChangeLock
{
    private const LOCKED_STATES = [
        OrderTransactionStates::STATE_UNCONFIRMED,
        AsyncOrderStates::STATE_PAYMENT_REVIEW,
        AsyncOrderStates::STATE_PENDING_BUYER_CONFIRMATION,
    ];

    private const DECIDED_MONDU_STATES = ['declined', 'canceled'];

    public function __construct(
        private readonly EntityRepository $orderRepository
    ) {}

    public function isLocked(string $orderId, Context $context): bool
    {
        return $this->lockedOrderIds([$orderId], $context) !== [];
    }

    /**
     * @param string[] $orderIds
     *
     * @return string[]
     */
    public function lockedOrderIds(array $orderIds, Context $context): array
    {
        $orderIds = array_values(array_filter($orderIds));
        if ($orderIds === []) {
            return [];
        }

        $criteria = new Criteria($orderIds);
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));
        $criteria->addAssociation(OrderExtension::EXTENSION_NAME);

        $locked = [];
        /** @var OrderEntity $order */
        foreach ($this->orderRepository->search($criteria, $context)->getEntities() as $order) {
            if ($this->isOrderLocked($order)) {
                $locked[] = $order->getId();
            }
        }

        return $locked;
    }

    private function isOrderLocked(OrderEntity $order): bool
    {
        /** @var OrderDataEntity|null $monduData */
        $monduData = $order->getExtension(OrderExtension::EXTENSION_NAME);
        if ($monduData === null) {
            return false;
        }

        // The transaction Shopware itself looks at to decide whether the payment is changeable.
        $transaction = $order->getTransactions()?->last();

        $paymentMethod = $transaction?->getPaymentMethod();
        if ($paymentMethod === null || !MethodHelper::isMonduPayment($paymentMethod)) {
            return false;
        }

        $state = $transaction->getStateMachineState()?->getTechnicalName();
        if (in_array($state, self::LOCKED_STATES, true)) {
            return true;
        }

        return $state === OrderTransactionStates::STATE_OPEN
            && $monduData->isAsyncFlow()
            && !in_array($monduData->getOrderState(), self::DECIDED_MONDU_STATES, true);
    }
}
