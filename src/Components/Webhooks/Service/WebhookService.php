<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Service;

use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Mondu\MonduPayment\Components\Webhooks\Model\Webhook;
use Psr\Log\LoggerInterface;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;



class WebhookService
{
    private MonduClient $monduClient;
    private StateMachineRegistry $stateMachineRegistry;
    private EntityRepositoryInterface $orderRepository;
    private LoggerInterface $logger;
    private $orderDataRepository;
    private ConfigService $configService;

    public function __construct(StateMachineRegistry $stateMachineRegistry, EntityRepositoryInterface $orderRepository, LoggerInterface $logger, MonduClient $monduClient, $orderDataRepository, ConfigService $configService)
    {
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->monduClient = $monduClient;
        $this->orderDataRepository = $orderDataRepository;
        $this->configService = $configService;
    }

    public function getSecret($key, $context)
    {
        try {

            $keys = $this->monduClient->getWebhooksSecret($key);

            if (isset($keys['webhook_secret']))
            {
                $this->configService->setWebhooksSecret($keys['webhook_secret']);
            }
            
        } catch (MonduException $e) {
            $this->log('Get Webhook Secret Failed', [], $e);
            return false;
        }
    }

    public function register($context): bool
    {
        try {
            $webhooks = [
                (new Webhook('order'))->getData(),
                (new Webhook('invoice'))->getData()
            ];

            foreach ($webhooks as $webhook) {
                $this->monduClient->registerWebhook($webhook);
            }
            
            return true;
        } catch (MonduException $e) {
            $this->log('register Webhook Failed', [], $e);
            return false;
        }
    }

    public function handleConfirmed($params, $context): array
    {
        try {
            $viban = @$params['viban'];
            $monduId = @$params['order_uuid'];
            $externalReferenceId = @$params['external_reference_id'];

            if (!$viban || !$externalReferenceId) {
                throw new MonduException('Missing params.');
            }

            // Update vIBAN

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('referenceId', $monduId));

            $orderDataId = $this->orderDataRepository->searchIds($criteria, $context)->firstId();

            $this->orderDataRepository->update([
                [
                    'id' => $orderDataId,
                    'viban' => $viban
                ]
            ], $context);

            $transitionResult = $this->transitionTransactionState($externalReferenceId, 'paid', $context);

            return [[ 'status' => $transitionResult->last()->getTechnicalName(), 'error' => 0 ], Response::HTTP_OK];
        } catch (MonduException $e) {
            $this->log('handleConfirmed Webhook Failed', [$params], $e);
            return [[ 'status' => $e->getMessage(), 'error' => 10 ], Response::HTTP_BAD_REQUEST];
        }
    }

    public function handlePending($params, $context): array
    {
        try {
            $externalReferenceId = @$params['external_reference_id'];
            $monduId = @$params['order_uuid'];

            if (!$externalReferenceId || !$monduId) {
                throw new MonduException('Required params missing');
            }

            $transitionResult = $this->transitionOrderState($externalReferenceId, 'process', $context);
            $transitionResult = $this->transitionTransactionState($externalReferenceId, 'reopen', $context);

            return [[ 'status' => $transitionResult->last()->getTechnicalName(), 'error' => 0 ], Response::HTTP_OK];
        } catch (MonduException $e) {
            $this->log('handlePending Webhook Failed', [$params], $e);
            return [[ 'status' => 'error', 'error' => 10 ], Response::HTTP_BAD_REQUEST];
        }
    }

    public function handleDeclinedOrCanceled($params, $context): array
    {
        try {
            $monduId = @$params['order_uuid'];
            $externalReferenceId = @$params['external_reference_id'];
            $orderState = @$params['order_state'];

            if (!$monduId || !$externalReferenceId || !$orderState) {
                $this->log('Required params missing', [$monduId, $externalReferenceId, $orderState]);
                throw new MonduException('Required params missing');
            }

            $transitionResult = $this->transitionOrderState($externalReferenceId, 'cancel', $context);
            $transitionResult = $this->transitionDeliveryState($externalReferenceId, 'cancel', $context);
            $transitionResult = $this->transitionTransactionState($externalReferenceId, 'cancel', $context);

            return [[ 'status' => $transitionResult->last()->getTechnicalName(), 'error' => 0 ], Response::HTTP_OK];
        } catch (MonduException $e) {
            $this->log('handleDeclinedOrCanceled Webhook Failed', [$params], $e);
            return [[ 'status' => $e->getMessage(), 'error' => 10 ], Response::HTTP_BAD_REQUEST];
        }
    }

    protected function transitionOrderState($externalReferenceId, $state, $context)
    {
        try {
            return $this->stateMachineRegistry->transition(new Transition(
                OrderDefinition::ENTITY_NAME,
                $this->getOrderUuid($externalReferenceId, $context),
                $state,
                'stateId'
            ), $context);
        } catch (\Exception $e) {
            $this->log('transitionOrderState Failed', [$externalReferenceId, $state], $e);
            throw new MonduException($e->getMessage());
        }
    }

    protected function transitionDeliveryState($externalReferenceId, $state, $context)
    {
        try {
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context)]);
            $criteria->addAssociation('deliveries');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $orderDeliveryId = $orderEntity->getDeliveries()->first()->getId();

            return $this->stateMachineRegistry->transition(new Transition(
                OrderDeliveryDefinition::ENTITY_NAME,
                $orderDeliveryId,
                $state,
                'stateId'
            ), $context);
        } catch (\Exception $e) {
            $this->log('transitionDeliveryState Failed', [$externalReferenceId, $state], $e);
            throw new MonduException($e->getMessage());
        }
    }

    protected function transitionTransactionState($externalReferenceId, $state, $context)
    {
        try {
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context)]);
            $criteria->addAssociation('transactions');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $orderTransactionId = $orderEntity->getTransactions()->first()->getId();

            return $this->stateMachineRegistry->transition(new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $orderTransactionId,
                $state,
                'stateId'
            ), $context);
        } catch (\Exception $e) {
            $this->log('transitionTransactionState Failed', [$externalReferenceId, $state], $e);
            throw new MonduException($e->getMessage());
        }
    }

    protected function getOrderUuid($externalReferenceId, $context)
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderNumber', $externalReferenceId));

            $orderId = $this->orderRepository->search($criteria, $context)->first()->getId();

            return $orderId;
        } catch (\Exception $e) {
            $this->log('getOrderUuid Failed', [$externalReferenceId], $e);
            throw new MonduException($e->getMessage());
        }
    }

    protected function log($message, $data, $exception = null)
    {
        $exceptionMessage = "";

        if ($exception != null) {
            $exceptionMessage = $exception->getMessage();
        }

        $this->logger->critical(
            $message . '. (Exception: '. $exceptionMessage .')',
            $data
        );
    }
}
