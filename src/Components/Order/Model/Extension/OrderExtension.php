<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Model\Extension;

use Mondu\MonduPayment\Components\Order\Model\Definition\OrderDataDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\RestrictDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Order Extension Class
 */
class OrderExtension extends EntityExtension
{
    public const EXTENSION_NAME = 'monduData';

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                self::EXTENSION_NAME,
                'id',
                'order_id',
                OrderDataDefinition::class,
                true
            ))->addFlags(new RestrictDelete(), new CascadeDelete())
        );
    }

    public function getDefinitionClass(): string
    {
        return OrderDefinition::class;
    }
}
