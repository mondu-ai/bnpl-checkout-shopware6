<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use Mondu\MonduPayment\Components\StateMachine\Subscriber\TransitionSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;

/**
 * A cancelled payment cancels the Mondu order only when the merchant cancelled it, in the admin
 * or through an integration, and only for the order's current payment. Shopware cancels
 * payments by itself too (abandoned checkout, payment method change, Mondu's webhook), and the
 * buyer may pay again under a new Mondu order then.
 */
class TransitionSubscriberPaymentCancelTest extends TestCase
{
    public function testTheMerchantCancellingTheMonduPaymentCancelsTheMonduOrder(): void
    {
        $this->expectMonduCancel(true, $this->adminContext(), 'current');
    }

    public function testShopwareCancellingThePaymentByItselfLeavesMonduAlone(): void
    {
        $this->expectMonduCancel(false, Context::createDefaultContext(), 'current');
    }

    public function testCancellingAnEarlierPaymentAttemptLeavesTheNewMonduOrderAlone(): void
    {
        $this->expectMonduCancel(false, $this->adminContext(), 'earlier');
    }

    public function testCancellingAnotherPaymentMethodLeavesMonduAlone(): void
    {
        $this->expectMonduCancel(false, $this->adminContext(), 'current', 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment');
    }

    private function expectMonduCancel(bool $expected, Context $context, string $cancelled, string $handler = MonduHandler::class): void
    {
        $order = $this->order($handler);

        $client = $this->createMock(MonduClient::class);
        $client->method('setSalesChannelId')->willReturnSelf();
        $client->expects($expected ? static::once() : static::never())
            ->method('cancelOrder')->with('mondu-uuid')->willReturn('canceled');

        $event = new StateMachineTransitionEvent(
            'order_transaction',
            $cancelled === 'current' ? 'transaction-2' : 'transaction-1',
            $this->state('open'),
            $this->state('cancelled'),
            $context
        );

        $this->subscriber($order, $client)->onTransition($event);
    }

    private function subscriber(OrderEntity $order, MonduClient $client): TransitionSubscriber
    {
        $orderRepository = $this->createMock(EntityRepository::class);
        $ids = $this->createMock(IdSearchResult::class);
        $ids->method('firstId')->willReturn($order->getId());
        $orderRepository->method('searchIds')->willReturn($ids);
        $orderRepository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context) => new EntitySearchResult('order', 1, new EntityCollection([$order]), null, $criteria, $context)
        );

        $orderDataRepository = $this->createMock(EntityRepository::class);
        $orderDataRepository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context) => new EntitySearchResult('mondu_order_data', 0, new EntityCollection(), null, $criteria, $context)
        );

        // The constructor differs between the 6.6 and 6.7 plugin; the rest are plain mocks.
        $given = ['orderRepository' => $orderRepository, 'orderDataRepository' => $orderDataRepository, 'monduClient' => $client];
        $arguments = [];
        foreach ((new \ReflectionClass(TransitionSubscriber::class))->getConstructor()->getParameters() as $parameter) {
            $arguments[] = $given[$parameter->getName()] ?? $this->createMock((string) $parameter->getType());
        }

        return new TransitionSubscriber(...$arguments);
    }

    /**
     * An order paid twice: an abandoned first attempt and the current Mondu payment.
     */
    private function order(string $handler): OrderEntity
    {
        $transactions = new OrderTransactionCollection();
        foreach (['transaction-1', 'transaction-2'] as $id) {
            $paymentMethod = new PaymentMethodEntity();
            $paymentMethod->setId('method-' . $id);
            $paymentMethod->setHandlerIdentifier($id === 'transaction-2' ? $handler : MonduHandler::class);

            $transaction = new OrderTransactionEntity();
            $transaction->setId($id);
            $transaction->setPaymentMethod($paymentMethod);
            $transactions->add($transaction);
        }

        $monduData = new OrderDataEntity();
        $monduData->setId('data');
        $monduData->assign(['orderId' => 'order', 'referenceId' => 'mondu-uuid', 'orderState' => 'authorized']);

        $order = new OrderEntity();
        $order->setId('order');
        $order->setSalesChannelId('channel');
        $order->setTransactions($transactions);
        $order->addExtension(OrderExtension::EXTENSION_NAME, $monduData);

        return $order;
    }

    private function state(string $technicalName): StateMachineStateEntity
    {
        $state = new StateMachineStateEntity();
        $state->setId($technicalName);
        $state->setTechnicalName($technicalName);

        return $state;
    }

    private function adminContext(): Context
    {
        return new Context(new AdminApiSource('user'));
    }
}
