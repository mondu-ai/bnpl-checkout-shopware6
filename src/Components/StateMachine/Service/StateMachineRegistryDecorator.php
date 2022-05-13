<?php

declare(strict_types=1);


namespace Mondu\MonduPayment\Components\StateMachine\Service;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduOperationService;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
//use Mondu\MonduPayment\Components\StateMachine\Exception\InvoiceNumberMissingException;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Shopware\Core\Checkout\Document\DocumentGenerator\InvoiceGenerator;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class StateMachineRegistryDecorator extends StateMachineRegistry // we must extend it, cause there is no interface
{
    /**
     * @var ConfigService
     */
    protected $configService;

    /**
     * @var EntityRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $orderDeliveryRepository;

    /**
     * @var StateMachineRegistry
     */
    private $innerService;
    private MonduOperationService $monduOperationService;

    /**
     * @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        StateMachineRegistry $innerService,
        ConfigService $configService,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderDeliveryRepository,
        MonduOperationService $monduOperationService
    ) {
        $this->innerService = $innerService;
        $this->configService = $configService;
        $this->orderRepository = $orderRepository;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->monduOperationService = $monduOperationService;
    }

    public function transition(Transition $transition, Context $context): StateMachineStateCollection
    {
        if($transition->getEntityName() === OrderDeliveryDefinition::ENTITY_NAME) {
            $orderDelivery = $this->orderDeliveryRepository->search(new Criteria([$transition->getEntityId()]), $context)->first();
            $order = $this->getOrder($orderDelivery->getOrderId(), $context);
            $transaction = $order ? $order->getTransactions()->first() : null;
            $paymentMethod = $transaction ? $transaction->getPaymentMethod() : null;
            $transitionName = $transition->getTransitionName();

            if($paymentMethod &&
                MethodHelper::isMonduPayment($paymentMethod) &&
                !$this->canShipOrder($order) &&
                ($transitionName == 'ship' || $transitionName == 'ship_partially')
            ) {
                throw new MonduException('Order can not be shipped.');
            }
        }

        return $this->innerService->transition($transition, $context);
    }

    protected function canShipOrder(OrderEntity $order): bool
    {
        /** @var OrderDataEntity $monduData */
        $monduData = $order->getExtension(OrderExtension::EXTENSION_NAME);
        if(!$monduData) {
            throw new MonduException('Corrupt order');
        }
        /**if ($monduData->getOrderState() === 'partially_shipped' || $monduData->getOrderState() === 'confirmed') {
         *
        } else */if($monduData->getOrderState() === 'pending') {
            $newState = $this->monduOperationService->syncOrder($monduData);
            if ($newState !=='partially_shipped' && $newState !== 'confirmed') {
                throw new MonduException('Mondu Order state must be confirmed or partially_shipped');
            }
        }
        $invoiceNumber = $monduData->getExternalInvoiceNumber();

        if (!$invoiceNumber) {
            foreach ($order->getDocuments() as $document) {
                if ($document->getDocumentType()->getTechnicalName() === InvoiceGenerator::INVOICE) {
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
