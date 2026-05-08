<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Invoice;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Document\DocumentEntity;

class InvoiceDataEntity extends Entity
{
    use EntityIdTrait;

    public const FIELD_ID = 'id';

    public const FIELD_ORDER_ID = 'orderId';

    public const FIELD_ORDER_VERSION_ID = 'orderVersionId';

    public const FIELD_DOCUMENT_ID = 'documentId';

    public const FIELD_INVOICE_NUMBER = 'invoiceNumber';

    public const FIELD_EXTERNAL_INVOICE_UUID = 'externalInvoiceUuid';

    public const FIELD_INVOICE_STATE = 'invoiceState';

    /**
     * @var string
     */
    protected string $orderId;

    /**
     * @var string
     */
    protected string $orderVersionId;

    /**
     * @var OrderEntity
     */
    protected OrderEntity $order;

    /**
     * @var string
     */
    protected string $documentId;

    /**
     * @var DocumentEntity
     */
    protected DocumentEntity $document;

    /**
     * @var string|null
     */
    protected ?string $invoiceNumber;

    /**
     * @var string|null
     */
    protected ?string $externalInvoiceUuid;

    protected ?string $invoiceState = null;

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function getDocument(): ?DocumentEntity
    {
        return $this->document;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function getExternalInvoiceUuid(): ?string
    {
        return $this->externalInvoiceUuid;
    }

    public function getInvoiceState(): ?string
    {
        return $this->invoiceState;
    }
}
