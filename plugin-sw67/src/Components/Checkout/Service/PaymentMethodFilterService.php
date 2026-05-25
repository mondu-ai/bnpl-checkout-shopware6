<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Service;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduOperationService;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Single place for all Mondu payment method filtering logic:
 * - Allowed methods from Mondu API
 * - API token validation
 * - Hide Mondu for B2C (private account type)
 */
class PaymentMethodFilterService
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly MonduOperationService $monduOperationService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Returns handler identifiers of Mondu payment methods that should be hidden.
     *
     * @return string[]
     */
    public function getDisallowedMonduHandlers(SalesChannelContext $context): array
    {
        $salesChannelId = $context->getSalesChannelId();
        $configService = $this->configService->setSalesChannelId($salesChannelId);

        $allowedPaymentMethods = $this->monduOperationService->getAllowedPaymentMethods($salesChannelId);
        $disallowedPaymentMethods = [];
        $allPaymentMethods = MethodHelper::MONDU_PAYMENT_METHODS;

        foreach ($allPaymentMethods as $value) {
            if (!in_array($value, $allowedPaymentMethods, true)) {
                $disallowedPaymentMethods[] = $value;
            }
        }

        if (!$configService->getApiTokenValid()) {
            $disallowedPaymentMethods = $allPaymentMethods;
        }

        $result = array_map(
            fn (string $name) => MethodHelper::monduNameToHandler($name),
            $disallowedPaymentMethods
        );

        return $result;
    }
}
