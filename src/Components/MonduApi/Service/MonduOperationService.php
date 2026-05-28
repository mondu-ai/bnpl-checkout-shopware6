<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\MonduApi\Service;

use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class MonduOperationService
{
    public function __construct(
        private readonly MonduClient $monduClient,
        private readonly EntityRepository $orderDataRepository,
        private readonly CacheItemPoolInterface $cache
    ) {}

    public function syncOrder(OrderDataEntity $orderData, Context $context, $salesChannelId = null)
    {
        $order = $this->monduClient->setSalesChannelId($salesChannelId)->getMonduOrder($orderData->getReferenceId());

        if ($order === null) {
            return null;
        }

        $this->orderDataRepository->update([
            [
                OrderDataEntity::FIELD_ID => $orderData->getId(),
                OrderDataEntity::FIELD_VIBAN => $order['bank_account']['iban'] ?? null,
                OrderDataEntity::FIELD_ORDER_STATE => $order['state'],
            ]
        ], $context);

        return $order['state'];
    }

    public function getAllowedPaymentMethods($salesChannelId = null): array
    {
        $cacheKey = 'mondu_payment_methods' . ($salesChannelId ? '_' . $salesChannelId : '');
        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $paymentMethods = $this->monduClient->setSalesChannelId($salesChannelId)->getPaymentMethods();
        if($paymentMethods) {
            $result = [];

            foreach ($paymentMethods['payment_methods'] as $value) {
                $result[] = $value['identifier'];
            }

            $cacheItem->set($result);
            $cacheItem->expiresAfter(3600);
            $this->cache->save($cacheItem);
        } else {
            $result = MethodHelper::MONDU_PAYMENT_METHODS;
        }

        return $result;
    }
}
