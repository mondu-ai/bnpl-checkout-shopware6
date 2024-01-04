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
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;

class MonduHandler implements SynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private MonduClient $monduClient;
    private $orderDataRepository;
    private $orderRepository;
    private ConfigService $configService;

    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        MonduClient $monduClient,
        $orderDataRepository,
        EntityRepositoryInterface $repository,
        ConfigService $configService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->monduClient = $monduClient;
        $this->orderDataRepository = $orderDataRepository;
        $this->orderRepository = $repository;
        $this->configService = $configService;
        $this->logger = $logger;
    }

    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $this->logger->alert(__CLASS__ . '::' . __FUNCTION__ . '::start');

        $monduData = $dataBag->get('mondu_payment', new ParameterBag([]));

        $this->logger->alert(__CLASS__ . '::' . __FUNCTION__, (array) $monduData);

        if ($monduData->count() === 0 || $monduData->has('order-id') === false || !$monduData->get('order-id')) {
            throw new SyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'unknown error during payment');
        }

        $order = $transaction->getOrder();
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelContext->getSalesChannelId())->getMonduOrder($monduData->get('order-id'));

        $this->logger->alert(__CLASS__ . '::' . __FUNCTION__, $monduOrder);
        if (!$monduOrder) {
            throw new SyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'unknown error during payment');
        }

        if($monduOrder['state'] === 'confirmed') {
            $orderTransactionState = $this->configService->setSalesChannelId($salesChannelContext->getSalesChannelId())->orderTransactionState();

            switch($orderTransactionState) {
                case 'paid':
                    $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                break;
                case 'authorized':
                    $this->transactionStateHandler->authorize($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                break;
            }
        }
        // Update external reference id on Mondu
        $this->logger->alert(__CLASS__ . '::' . __FUNCTION__, [
            'external_reference_id' => $order->getOrderNumber(),
            'order-id' => $monduData->get('order-id'),
            'sales-channel-id' => $salesChannelContext->getSalesChannelId()
        ]);

        try {
            $data = $this->monduClient
                ->setSalesChannelId($salesChannelContext->getSalesChannelId())
                ->updateExternalInfo(
                    $monduData->get('order-id'),
                    ['external_reference_id' => $order->getOrderNumber()]
                );

            $this->logger->alert(__CLASS__ . '::' . __FUNCTION__, (array) $data);
        } catch (\Exception $e) {
            $this->logger->alert(__CLASS__ . '::' . __FUNCTION__ . '::Error',
                [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTrace()
                ]
            );
        }

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

        $this->logger->alert(__CLASS__ . '::' . __FUNCTION__ . '::end');
    }
}
