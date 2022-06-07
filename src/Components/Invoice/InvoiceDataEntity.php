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

    /**
     * @var string
     */
    protected $orderId;

    /**
     * @var string
     */
    protected $orderVersionId;

    /**
     * @var OrderEntity
     */
    protected $order;

    /**
     * @var string
     */
    protected $documentId;

    /**
     * @var DocumentEntity
     */
    protected $document;

    /**
     * @var string|null
     */
    protected $invoiceNumber;

    /**
     * @var string|null
     */
    protected $externalInvoiceUuid;


    public function getId(): ?string
    {
        return $this->name;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function getDocument(): DocumentEntity
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
}
