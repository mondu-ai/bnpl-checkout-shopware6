<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder\Service;

use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderFields;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderLinesService;
use Mondu\MonduPayment\Util\AddressLines;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Builds the POST /orders/create_async payload for an order created in the admin.
 *
 * The request goes out while the cart is converted, before the order is written, so a
 * refused order never exists in Shopware. At that point there is no order entity yet:
 * the lines are computed from an OrderEntity assembled from the converted cart, so the
 * same OrderLinesService as the hosted checkout builds them, with the very line item ids
 * the order is written with. That keeps each line's external_reference_id equal to the
 * one the invoice sends later on.
 */
class AsyncOrderPayloadBuilder
{
    /**
     * Languages Mondu writes its buyer emails in. Anything else is left out, and Mondu
     * then derives the language from the billing country.
     */
    private const SUPPORTED_LANGUAGES = ['de', 'en', 'nl', 'fr', 'el'];

    public function __construct(
        private readonly AbstractOrderLinesService $orderLinesService,
        private readonly ConfigService $configService
    ) {}

    /**
     * @param array<string, mixed> $convertedCart the order data CartConvertedEvent carries
     * @param string $externalReferenceId the reference the order goes to Mondu with
     * @param array<string, string> $fields the fields the merchant filled in the admin
     * @return array<string, mixed>
     */
    public function build(
        array $convertedCart,
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        string $paymentMethod,
        string $externalReferenceId,
        array $fields
    ): array {
        $customer = $salesChannelContext->getCustomer();
        $billingAddress = $customer?->getActiveBillingAddress();

        if ($customer === null || $billingAddress === null) {
            throw new \RuntimeException('The order has no customer or billing address.');
        }

        $shippingAddress = $cart->getDeliveries()->first()?->getLocation()->getAddress() ?? $billingAddress;

        $config = $this->configService->setSalesChannelId($salesChannelContext->getSalesChannelId());
        $handling1 = $config->getHandlingAddressAdditionalField1();
        $handling2 = $config->getHandlingAddressAdditionalField2();

        [$billingLine1, $billingLine2] = AddressLines::build($billingAddress, $handling1, $handling2);
        [$shippingLine1, $shippingLine2] = AddressLines::build($shippingAddress, $handling1, $handling2);

        $payload = [
            'currency' => $salesChannelContext->getCurrency()->getIsoCode(),
            'external_reference_id' => $externalReferenceId,
            'payment_method' => $paymentMethod,
            'gross_amount_cents' => (int) round($convertedCart['price']->getTotalPrice() * 100),
            'buyer' => $this->buildBuyer($customer, $billingAddress, $billingLine1, $billingLine2, $paymentMethod, $fields),
            'billing_address' => $this->buildAddress($billingAddress, $billingLine1, $billingLine2),
            'shipping_address' => $this->buildAddress($shippingAddress, $shippingLine1, $shippingLine2),
            'lines' => $this->orderLinesService->getLines(
                $this->createOrderEntity($convertedCart),
                $salesChannelContext->getContext()
            ),
        ];

        $language = $this->resolveLanguageCode($salesChannelContext);
        if ($language !== null) {
            $payload['language'] = $language;
        }

        $required = AsyncOrderFields::requiredFor($paymentMethod);

        if (in_array(AsyncOrderFields::FIELD_NET_TERM, $required, true) && isset($fields[AsyncOrderFields::FIELD_NET_TERM])) {
            $payload['net_term'] = (int) $fields[AsyncOrderFields::FIELD_NET_TERM];
        }

        if (in_array(AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS, $required, true) && isset($fields[AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS])) {
            $payload['number_of_installments'] = (int) $fields[AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS];
        }

        $owners = $this->buildOwners($customer, $fields);
        if ($owners !== []) {
            $payload['owners'] = $owners;
        }

        return $payload;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function buildBuyer(
        CustomerEntity $customer,
        CustomerAddressEntity $billingAddress,
        string $addressLine1,
        ?string $addressLine2,
        string $paymentMethod,
        array $fields
    ): array {
        $buyer = [
            'email' => $customer->getEmail(),
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            // The same source as the hosted checkout (order customer company), the address as fallback.
            'company_name' => $customer->getCompany() ?: $billingAddress->getCompany(),
            'phone' => $billingAddress->getPhoneNumber(),
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2,
            'zip_code' => $billingAddress->getZipCode(),
            'is_registered' => !$customer->getGuest(),
            'external_reference_id' => $customer->getCustomerNumber(),
            'account_created_at' => $customer->getCreatedAt(),
            'account_updated_at' => $customer->getUpdatedAt(),
        ];

        $vatIds = $customer->getVatIds();
        $vatId = is_array($vatIds) ? ($vatIds[0] ?? null) : null;
        if ($vatId !== null && $vatId !== '') {
            $buyer['vat_number'] = (string) $vatId;
        }

        $visible = AsyncOrderFields::visibleFor($paymentMethod);
        $buyerFields = [
            AsyncOrderFields::FIELD_REGISTRATION_ID,
            AsyncOrderFields::FIELD_LEGAL_FORM_CATEGORY,
            AsyncOrderFields::FIELD_IBAN,
            AsyncOrderFields::FIELD_ACCOUNT_HOLDER,
        ];

        foreach ($buyerFields as $field) {
            if (in_array($field, $visible, true) && isset($fields[$field])) {
                $buyer[$field] = $fields[$field];
            }
        }

        return $buyer;
    }

    /**
     * The `owners` object, which the API wants (with a birth date) only for sole traders.
     *
     * Sending an owner without a birth date fails with "owners.0.birth_date is missing",
     * so the key is left out for every other legal form.
     *
     * @param array<string, string> $fields
     * @return array<int, array<string, string>>
     */
    private function buildOwners(CustomerEntity $customer, array $fields): array
    {
        if (($fields[AsyncOrderFields::FIELD_LEGAL_FORM_CATEGORY] ?? null) !== AsyncOrderFields::LEGAL_FORM_CATEGORY_SOLE_TRADER) {
            return [];
        }

        $firstName = $fields[AsyncOrderFields::FIELD_OWNER_FIRST_NAME] ?? $customer->getFirstName();
        $lastName = $fields[AsyncOrderFields::FIELD_OWNER_LAST_NAME] ?? $customer->getLastName();
        $birthDate = $fields[AsyncOrderFields::FIELD_OWNER_BIRTH_DATE] ?? '';

        if ($firstName === '' || $lastName === '' || $birthDate === '') {
            return [];
        }

        return [[
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
        ]];
    }

    /**
     * @return array<string, string|null>
     */
    private function buildAddress(CustomerAddressEntity $address, string $addressLine1, ?string $addressLine2): array
    {
        return [
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2,
            'city' => $address->getCity(),
            'country_code' => $address->getCountry()?->getIso(),
            'zip_code' => $address->getZipCode(),
        ];
    }

    /**
     * The order as OrderLinesService reads it: line items, prices and tax status.
     *
     * @param array<string, mixed> $convertedCart
     */
    private function createOrderEntity(array $convertedCart): OrderEntity
    {
        $lineItems = new OrderLineItemCollection();

        foreach ($convertedCart['lineItems'] ?? [] as $data) {
            $lineItem = new OrderLineItemEntity();
            $lineItem->setId($data['id']);
            $lineItem->setUniqueIdentifier($data['id']);
            $lineItem->setIdentifier((string) ($data['identifier'] ?? $data['id']));
            $lineItem->setReferencedId($data['referencedId'] ?? null);
            $lineItem->setQuantity((int) ($data['quantity'] ?? 1));
            $lineItem->setType($data['type'] ?? null);
            $lineItem->setLabel((string) ($data['label'] ?? ''));
            $lineItem->setPrice($data['price'] ?? null);
            $lineItem->setPayload($data['payload'] ?? null);
            $lineItem->setPosition((int) ($data['position'] ?? 1));

            if (isset($data['parentId'])) {
                $lineItem->setParentId($data['parentId']);
            }

            $lineItems->add($lineItem);
        }

        $order = new OrderEntity();
        $order->setId($convertedCart['id']);
        $order->setUniqueIdentifier($convertedCart['id']);
        $order->setOrderNumber((string) $convertedCart['orderNumber']);
        $order->setPrice($convertedCart['price']);
        $order->setShippingCosts($convertedCart['shippingCosts']);
        $order->setTaxStatus($convertedCart['price']->getTaxStatus());
        $order->setLineItems($lineItems);

        return $order;
    }

    private function resolveLanguageCode(SalesChannelContext $salesChannelContext): ?string
    {
        $locale = $salesChannelContext->getLanguageInfo()?->localeCode ?? '';

        if ($locale === '') {
            return null;
        }

        // Shopware stores locales as 'fr-FR' or 'de-DE'; Mondu expects the language part alone.
        $language = strtolower(substr($locale, 0, 2));

        return in_array($language, self::SUPPORTED_LANGUAGES, true) ? $language : null;
    }
}
