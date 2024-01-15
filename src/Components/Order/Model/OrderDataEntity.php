<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Model;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Order Data Entity Class
 */
class OrderDataEntity extends Entity
{
    use EntityIdTrait;

    public const FIELD_ID = 'id';

    public const FIELD_ORDER_ID = 'orderId';

    public const FIELD_ORDER_VERSION_ID = 'orderVersionId';

    public const FIELD_ORDER_STATE = 'orderState';

    public const FIELD_REFERENCE_ID = 'referenceId';

    public const FIELD_IS_SUCCESSFUL = 'successful';

    public const FIELD_EXTERNAL_INVOICE_NUMBER = 'externalInvoiceNumber';

    public const FIELD_EXTERNAL_INVOICE_URL = 'externalInvoiceUrl';

    public const FIELD_EXTERNAL_DELIVERY_NOTE_URL = 'externalDeliveryNoteUrl';

    public const FIELD_VIBAN = 'viban';

    public const FIELD_DURATION = 'duration';

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
    protected string $orderState;

    /**
     * @var string
     */
    protected string $referenceId;

    /**
     * @var string|null
     */
    protected ?string $externalInvoiceNumber;

    /**
     * @var string|null
     */
    protected ?string $externalInvoiceUrl;

    /**
     * @var string|null
     */
    protected ?string $externalDeliveryNoteUrl;

    /**
     * @var string|null
     */
    protected ?string $bankIban;

    /**
     * @var string|null
     */
    protected ?string $bankBic;

    /**
     * @var string|null
     */
    protected ?string $bankName;

    /**
     * @var int|null
     */
    protected ?int $duration;

    /**
     * @var bool
     */
    protected bool $successful;

    /**
     * @var string|null
     */
    protected ?string $viban;

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function getOrderState(): string
    {
        return $this->orderState;
    }

    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    public function getExternalInvoiceNumber(): ?string
    {
        return $this->externalInvoiceNumber;
    }

    public function getExternalInvoiceUrl(): ?string
    {
        return $this->externalInvoiceUrl;
    }

    public function getExternalDeliveryNoteUrl(): ?string
    {
        return $this->externalDeliveryNoteUrl;
    }

    public function getViban(): ?string
    {
        return $this->viban;
    }

    public function getBankIban(): ?string
    {
        return $this->bankIban;
    }

    public function getBankBic(): ?string
    {
        return $this->bankBic;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }
}
