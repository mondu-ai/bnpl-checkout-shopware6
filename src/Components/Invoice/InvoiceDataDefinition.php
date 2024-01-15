<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Invoice;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\VersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Checkout\Document\DocumentDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;

/**
 * Invoice Data Definition Class
 */
class InvoiceDataDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'mondu_invoice_data';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return InvoiceDataEntity::class;
    }

    public function getCollectionClass(): string
    {
        return InvoiceDataCollection::class;
    }

    protected function defaultFields(): array
    {
        return [
            new CreatedAtField(),
            new UpdatedAtField(),
        ];
    }

    protected function getParentDefinitionClass(): ?string
    {
        return OrderDefinition::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField(
                'id',
                InvoiceDataEntity::FIELD_ID
            ))->addFlags(new Required(), new PrimaryKey()),
            new VersionField(),

            (new FkField(
                'order_id',
                InvoiceDataEntity::FIELD_ORDER_ID,
                OrderDefinition::class
            ))->addFlags(new Required()),
            (new ReferenceVersionField(OrderDefinition::class))->addFlags(new Required()),

            (new FkField(
                'document_id',
                InvoiceDataEntity::FIELD_DOCUMENT_ID,
                DocumentDefinition::class
            ))->addFlags(new Required()),

            (new StringField(
                'invoice_number',
                InvoiceDataEntity::FIELD_INVOICE_NUMBER
            )),

            (new StringField(
                'external_invoice_uuid',
                InvoiceDataEntity::FIELD_EXTERNAL_INVOICE_UUID
            )),

            new OneToOneAssociationField('order', 'order_id', 'id', OrderDefinition::class, false),
            new OneToOneAssociationField('document', 'document_id', 'id', DocumentDefinition::class, false),
        ]);
    }
}
