<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\Order\Service\PaymentChangeLock;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PrePayment;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

class PaymentChangeLockTest extends TestCase
{
    public function testAnOrderMonduIsStillAssessingIsLocked(): void
    {
        // Hosted checkout, Mondu reviewing.
        static::assertTrue($this->isLocked($this->order('unconfirmed', 'pending', 'hosted')));
        // Created in the admin, waiting in one of the Mondu states.
        static::assertTrue($this->isLocked($this->order('mondu_payment_review', 'pending', 'async')));
        static::assertTrue($this->isLocked($this->order('mondu_pending_buyer_confirmation', 'authorized', 'async')));
        // Created in the admin, Mondu's first answer not there yet.
        static::assertTrue($this->isLocked($this->order('open', 'processing', 'async')));
    }

    public function testAFailedOrAbandonedPaymentCanBePaidAnotherWay(): void
    {
        // Declined order created in the admin.
        static::assertFalse($this->isLocked($this->order('failed', 'declined', 'async')));
        static::assertFalse($this->isLocked($this->order('open', 'declined', 'async')));
        // Hosted checkout the buyer left, or never finished.
        static::assertFalse($this->isLocked($this->order('cancelled', 'pending', 'hosted')));
        static::assertFalse($this->isLocked($this->order('open', 'pending', 'hosted')));
    }

    public function testOrdersPaidWithoutMonduAreLeftToShopware(): void
    {
        static::assertFalse($this->isLocked($this->order('unconfirmed', 'pending', 'async', PrePayment::class)));
        static::assertFalse($this->isLocked($this->order('unconfirmed', null, null)));
    }

    public function testOnlyTheLatestTransactionCounts(): void
    {
        // The buyer already switched away from Mondu after a declined order.
        $order = $this->order('failed', 'declined', 'async');
        $order->getTransactions()->add($this->transaction('unconfirmed', PrePayment::class, 'second'));

        static::assertFalse($this->isLocked($order));
    }

    private function isLocked(OrderEntity $order): bool
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context) => new EntitySearchResult(
                'order',
                1,
                new OrderCollection([$order]),
                null,
                $criteria,
                $context
            )
        );

        return (new PaymentChangeLock($repository))->isLocked($order->getId(), Context::createDefaultContext());
    }

    private function order(string $transactionState, ?string $monduState, ?string $flow, string $handler = MonduHandler::class): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order');
        $order->setTransactions(new OrderTransactionCollection([$this->transaction($transactionState, $handler, 'first')]));

        if ($monduState !== null) {
            $data = new OrderDataEntity();
            $data->setId('data');
            $data->assign(['orderState' => $monduState, 'orderFlow' => $flow, 'referenceId' => 'uuid']);
            $order->addExtension(OrderExtension::EXTENSION_NAME, $data);
        }

        return $order;
    }

    private function transaction(string $state, string $handler, string $id): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setId($state);
        $stateEntity->setTechnicalName($state);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId($handler);
        $paymentMethod->setHandlerIdentifier($handler);

        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setStateMachineState($stateEntity);
        $transaction->setPaymentMethod($paymentMethod);

        return $transaction;
    }
}
