<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Util;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Criteria Helper Class
 */
class CriteriaHelper
{
    public static function getCriteriaForOrder(string $orderId): Criteria
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('addresses.country')
            ->addAssociation('addresses.salutation')
            ->addAssociation('deliveries')
            ->addAssociation('lineItems')
            ->addAssociation('transactions.paymentMethod');

        // sort by latest transactions to get the current transaction
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));

        return $criteria;
    }
}
