<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\MonduApi\Service;

use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

class MonduOperationService
{
    private MonduClient $monduClient;
    private EntityRepositoryInterface $orderDataRepository;

    public function __construct(MonduClient $monduClient, EntityRepositoryInterface $orderDataRepository)
    {
        $this->monduClient = $monduClient;
        $this->orderDataRepository = $orderDataRepository;
    }

    public function syncOrder(OrderDataEntity $orderData)
    {
        $order = $this->monduClient->getMonduOrder($orderData->getReferenceId());
        $this->orderDataRepository->update([
            [
                OrderDataEntity::FIELD_ID => $orderData->getId(),
                OrderDataEntity::FIELD_VIBAN => null, //$order['buyer']['viban'],
                OrderDataEntity::FIELD_ORDER_STATE => $order['state'],
            ]
        ], Context::createDefaultContext());

        return $order['state'];
    }
}
