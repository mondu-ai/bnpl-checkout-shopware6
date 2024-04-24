<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\StateMachine\Service;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduOperationService;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class StateMachineRegistryDecorator extends StateMachineRegistry // we must extend it, cause there is no interface
{
    /**
     * @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        private readonly StateMachineRegistry $innerService,
        protected ConfigService $configService,
        protected EntityRepository $orderRepository,
        protected EntityRepository $orderDeliveryRepository,
        private readonly MonduOperationService $monduOperationService
    ) {}

    public function transition(Transition $transition, Context $context): StateMachineStateCollection
    {
        if ($transition->getEntityName() === OrderDeliveryDefinition::ENTITY_NAME) {
            $orderDelivery = $this->orderDeliveryRepository->search(new Criteria([$transition->getEntityId()]), $context)->first();
            $order = $this->getOrder($orderDelivery->getOrderId(), $context);
            $transaction = $order?->getTransactions()->first();
            $paymentMethod = $transaction ? $transaction->getPaymentMethod() : null;
            $transitionName = $transition->getTransitionName();

            if (MethodHelper::isMonduPayment($paymentMethod)) { 
                if (!$this->configService->skipOrderStateValidation()) {

                    if ($transitionName == 'reopen' && !$this->canCancelOrder($order)) {
//                        throw new MonduException('Order was canceled.');
                    }

                    if ($transitionName == 'ship' || $transitionName == 'ship_partially') {
                        if (!$this->canShipOrder($order, $context, $order->getSalesChannelId())) {
                            throw new MonduException('Order can not be shipped. Invoice required.');
                        }

                        $documentIds = $context->getExtensions()['mail-attachments']->getDocumentIds();

                        if (count($documentIds) != 1) {
                            throw new MonduException('Please select one document to attach.');
                        }
                    }
                }
            }
        }

        return $this->innerService->transition($transition, $context);
    }

    protected function canCancelOrder(OrderEntity $order): bool
    {
        /** @var OrderDataEntity $monduData */
        $monduData = $order->getExtension(OrderExtension::EXTENSION_NAME);
        if (!$monduData) {
            throw new MonduException('Corrupt order');
        }

        if ($monduData->getOrderState() === 'canceled') {
            return false;
        }

        return true;
    }

    protected function canShipOrder(OrderEntity $order, Context $context, ?string $salesChannelId = null): bool
    {
        /** @var OrderDataEntity $monduData */
        $monduData = $order->getExtension(OrderExtension::EXTENSION_NAME);
        if (!$monduData) {
            throw new MonduException('Corrupt order');
        }

        if ($monduData->getOrderState() === 'pending') {
            $newState = $this->monduOperationService->syncOrder($monduData, $context, $salesChannelId);
            if ($newState !=='partially_shipped' && $newState !== 'confirmed') {
                throw new MonduException('Mondu Order state must be confirmed or partially_shipped');
            }
        }
        $invoiceNumber = $monduData->getExternalInvoiceNumber();

        if (!$invoiceNumber) {
            foreach ($order->getDocuments() as $document) {
                if ($document->getDocumentType()->getTechnicalName() === 'invoice') {
                    $config = $document->getConfig();

                    return isset($config['custom']['invoiceNumber']);
                }
            }
            return false;
        }

        return true;
    }

    protected function getOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);
        $criteria->addAssociation('documents.documentType');

        return $this->orderRepository->search($criteria, $context)->first();
    }

    // not changed methods

    public function getInitialState(string $stateMachineName, Context $context): StateMachineStateEntity
    {
        return $this->innerService->getInitialState($stateMachineName, $context);
    }

    public function getAvailableTransitions(string $entityName, string $entityId, string $stateFieldName, Context $context): array
    {
        return $this->innerService->getAvailableTransitions($entityName, $entityId, $stateFieldName, $context);
    }

    public function getStateMachine(string $name, Context $context): StateMachineEntity
    {
        return $this->innerService->getStateMachine($name, $context);
    }
}
