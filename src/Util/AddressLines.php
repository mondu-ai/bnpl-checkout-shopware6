<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Util;

/**
 * Builds Mondu's address_line1 / address_line2 from a Shopware address.
 *
 * Where the two additional address lines go is set in the plugin config
 * (handlingAddressAdditionalField1/2: ignore, addtoaddressline1, addtoaddressline2).
 * Works for order addresses and customer addresses alike, both expose the same getters.
 */
final class AddressLines
{
    private function __construct()
    {
    }

    /**
     * @return array{0: string, 1: string|null} [address_line1, address_line2]
     */
    public static function build(object $address, string $handlingField1, string $handlingField2): array
    {
        $addressLine1 = (string) $address->getStreet();
        $addressLine2 = null;
        $addition1 = $address->getAdditionalAddressLine1();
        $addition2 = $address->getAdditionalAddressLine2();

        if ($handlingField1 === 'addtoaddressline1' && !empty($addition1)) {
            $addressLine1 .= ' ' . $addition1;
        } elseif ($handlingField1 === 'addtoaddressline2' && !empty($addition1)) {
            $addressLine2 = $addition1;
        }

        if ($handlingField2 === 'addtoaddressline2' && !empty($addition2)) {
            // Appended to the first additional line when that one went there too, without the
            // leading space the old inline code left when it was alone.
            $addressLine2 = ltrim($addressLine2 . ' ' . $addition2);
        } elseif ($handlingField2 === 'addtoaddressline1' && !empty($addition2)) {
            $addressLine1 .= ' ' . $addition2;
        }

        return [$addressLine1, $addressLine2];
    }
}
