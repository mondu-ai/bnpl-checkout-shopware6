<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Model\Collection;

use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                 add(OrderDataEntity $entity)
 * @method void                 set(string $key, OrderDataEntity $entity)
 * @method OrderDataEntity[]    getIterator()
 * @method OrderDataEntity[]    getElements()
 * @method OrderDataEntity|null get(string $key)
 * @method OrderDataEntity|null first()
 * @method OrderDataEntity|null last()
 */
class OrderDataCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return OrderDataEntity::class;
    }
}
