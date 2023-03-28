<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\MonduApi\Service;

use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

class MonduOperationService
{
    private MonduClient $monduClient;
    private EntityRepositoryInterface $orderDataRepository;
    private CacheItemPoolInterface $cache;

    public function __construct(MonduClient $monduClient, EntityRepositoryInterface $orderDataRepository, CacheItemPoolInterface $cache)
    {
        $this->monduClient = $monduClient;
        $this->orderDataRepository = $orderDataRepository;
        $this->cache = $cache;
    }

    public function syncOrder(OrderDataEntity $orderData, $salesChannelId = null, Context $context)
    {
        $order = $this->monduClient->setSalesChannelId($salesChannelId)->getMonduOrder($orderData->getReferenceId());
        $this->orderDataRepository->update([
            [
                OrderDataEntity::FIELD_ID => $orderData->getId(),
                OrderDataEntity::FIELD_VIBAN => null, //$order['buyer']['viban'],
                OrderDataEntity::FIELD_ORDER_STATE => $order['state'],
            ]
        ], $context);

        return $order['state'];
    }

    public function getAllowedPaymentMethods($salesChannelId = null): array
    {
        $cacheItem = $this->cache->getItem('mondu_payment_methods');
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $paymentMethods = $this->monduClient->setSalesChannelId($salesChannelId)->getPaymentMethods();
        if($paymentMethods) {
            $result = [];

            foreach ($paymentMethods['payment_methods'] as $value) {
                $result[] = $value['identifier'];
            }
        } else {
            $result = MethodHelper::MONDU_PAYMENT_METHODS;
        }

        $cacheItem->set($result);
        $cacheItem->expiresAfter(3600);
        $this->cache->save($cacheItem);
        $this->cache->commit();

        return $result;
    }
}
