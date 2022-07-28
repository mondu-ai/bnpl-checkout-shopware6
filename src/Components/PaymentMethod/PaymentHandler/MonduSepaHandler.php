<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler;

use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Symfony\Component\HttpFoundation\ParameterBag;

class MonduSepaHandler implements SynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private MonduClient $monduClient;
    private $orderDataRepository;

    public function __construct(OrderTransactionStateHandler $transactionStateHandler, MonduClient $monduClient, $orderDataRepository, EntityRepositoryInterface $repository)
    {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->monduClient = $monduClient;
        $this->orderDataRepository = $orderDataRepository;
        $this->orderRepository = $repository;
    }

    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $monduData = $dataBag->get('mondu_payment', new ParameterBag([]));
        if ($monduData->count() === 0 || $monduData->has('order-id') === false || !$monduData->get('order-id')) {
            throw new SyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'unknown error during payment');
        }

        $order = $transaction->getOrder();
        $monduOrder = $this->monduClient->getMonduOrder($monduData->get('order-id'));
        if (!$monduOrder) {
            throw new SyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'unknown error during payment');
        }

        $this->orderRepository->update([
            [
                'id' => $order->getId(),
                'orderNumber' => $monduOrder['external_reference_id']
            ]
        ], $salesChannelContext->getContext());

        $this->orderDataRepository->upsert([
            [
                OrderDataEntity::FIELD_ORDER_ID => $order->getId(),
                OrderDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                OrderDataEntity::FIELD_REFERENCE_ID => $monduOrder['uuid'],
                OrderDataEntity::FIELD_ORDER_STATE => $monduOrder['state'],
                OrderDataEntity::FIELD_VIBAN => $monduOrder['bank_account']['iban'],
                OrderDataEntity::FIELD_DURATION => $monduOrder['authorized_net_term'],
                OrderDataEntity::FIELD_IS_SUCCESSFUL => true,
            ]
        ], $salesChannelContext->getContext());
    }
}
