<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Service;

use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderFields;
use Mondu\MonduPayment\Components\AsyncOrder\Service\PaymentTermsService;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;

/**
 * Net terms a buyer may choose from in the storefront checkout.
 *
 * The terms are narrowed the way order creation validates them, by payment method and
 * by country, or the checkout offers something the API then refuses with 422
 * "proposed net terms is not available for merchant". Two sources are intersected,
 * the same as in the Magento module. The merchant's per method setting decides what
 * to offer at all, and GET /payment_terms with source=widget and the payment method
 * decides which of those the buyer's country may use.
 */
class NetTermOfferService
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly PaymentTermsService $paymentTermsService
    ) {}

    /**
     * Sorted terms to offer, none when the method carries no term or the merchant enabled none.
     *
     * @return int[]
     */
    public function getOfferedNetTerms(?string $salesChannelId, string $monduPaymentMethod, ?string $countryCode): array
    {
        if (!in_array($monduPaymentMethod, AsyncOrderFields::NET_TERM_METHODS, true) || $countryCode === null) {
            return [];
        }

        $configured = $this->configService->setSalesChannelId($salesChannelId)->getAvailableNetTerms($monduPaymentMethod);
        if ($configured === []) {
            return [];
        }

        $available = $this->paymentTermsService->getNetTerms(
            $salesChannelId,
            $countryCode,
            PaymentTermsService::SOURCE_WIDGET,
            $monduPaymentMethod
        );

        return array_values(array_intersect($configured, $available));
    }

    public function pickDefault(array $netTerms): ?int
    {
        return $this->paymentTermsService->pickDefault($netTerms);
    }

    /**
     * The term to send with the order: the buyer's choice when it is on offer, the
     * preselected one when the choice is missing or not on offer, none when nothing is.
     *
     * Checked again here rather than trusted from the form: the offer can change between
     * the page load and the order, and a term outside it fails the whole order.
     */
    public function resolve(?string $salesChannelId, string $monduPaymentMethod, ?string $countryCode, mixed $requested): ?int
    {
        $offered = $this->getOfferedNetTerms($salesChannelId, $monduPaymentMethod, $countryCode);

        if (is_numeric($requested) && in_array((int) $requested, $offered, true)) {
            return (int) $requested;
        }

        return $this->pickDefault($offered);
    }
}
