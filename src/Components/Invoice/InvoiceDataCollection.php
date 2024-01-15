<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Invoice;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                   add(InvoiceDataEntity $entity)
 * @method void                   set(string $key, InvoiceDataEntity $entity)
 * @method InvoiceDataEntity[]    getIterator()
 * @method InvoiceDataEntity[]    getElements()
 * @method InvoiceDataEntity|null get(string $key)
 * @method InvoiceDataEntity|null first()
 * @method InvoiceDataEntity|null last()
 */
class InvoiceDataCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return InvoiceDataEntity::class;
    }
}
