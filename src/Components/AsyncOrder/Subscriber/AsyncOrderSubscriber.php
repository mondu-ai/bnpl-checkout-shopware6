<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder\Subscriber;

use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderErrorFormatter;
use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderFields;
use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderStates;
use Mondu\MonduPayment\Components\AsyncOrder\Service\AsyncOrderFieldStorage;
use Mondu\MonduPayment\Components\AsyncOrder\Service\AsyncOrderPayloadBuilder;
use Mondu\MonduPayment\Components\AsyncOrder\Service\AsyncOrderStateSync;
use Mondu\MonduPayment\Components\AsyncOrder\Service\BackendOrdersService;
use Mondu\MonduPayment\Components\AsyncOrder\Service\PaymentTermsService;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Cart\Order\IdStruct;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Framework\Api\Context\AdminSalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends an order the merchant creates in the admin to Mondu (POST /orders/create_async).
 *
 * The admin has no hosted checkout to send the buyer to, so the payment handler never
 * runs for such an order. Instead the order goes to Mondu while the cart is converted
 * into it: an order Mondu refuses is then never written, and the admin shows Mondu's
 * reason. The Mondu reference is written together with the order through the monduData
 * association. Mondu assesses the order and asks the buyer to confirm it by email; the
 * result arrives through the order webhooks (see WebhookService).
 */
class AsyncOrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BackendOrdersService $backendOrdersService,
        private readonly AsyncOrderFieldStorage $fieldStorage,
        private readonly AsyncOrderPayloadBuilder $payloadBuilder,
        private readonly MonduClient $monduClient,
        private readonly AsyncOrderStateSync $stateSync,
        private readonly EntityRepository $orderDataRepository,
        private readonly LoggerInterface $logger,
        private readonly PaymentTermsService $paymentTermsService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();

        if (!$salesChannelContext->getContext()->getSource() instanceof AdminSalesChannelApiSource) {
            return;
        }

        // The same conversion runs when an existing order is edited and recalculated in
        // the admin. That order already went to Mondu when it was created.
        if ($event->getCart()->hasExtensionOfType(OrderConverter::ORIGINAL_ID, IdStruct::class)) {
            return;
        }

        $convertedCart = $event->getConvertedCart();
        $paymentMethod = $salesChannelContext->getPaymentMethod();

        if (!isset($convertedCart['orderNumber']) || !MethodHelper::isMonduPayment($paymentMethod)) {
            return;
        }

        $method = MethodHelper::shortNameToMonduName($paymentMethod->getShortName());
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        if (in_array($method, AsyncOrderFields::ADMIN_EXCLUDED_METHODS, true)
            || !$this->backendOrdersService->isActive($salesChannelId)
        ) {
            throw new MonduException('Mondu: this payment method is not available for orders created in the admin.');
        }

        $fields = $this->fieldStorage->load($salesChannelContext->getToken());
        $countryCode = $salesChannelContext->getCustomer()?->getActiveBillingAddress()?->getCountry()?->getIso();

        $fields = $this->withDefaultNetTerm($fields, $method, $salesChannelId, $countryCode);
        $this->validate($method, $countryCode, $fields);

        $customer = $salesChannelContext->getCustomer();
        if (AsyncOrderFields::companyName($customer?->getCompany(), $customer?->getActiveBillingAddress()?->getCompany()) === null) {
            // Mondu answers with "company name is required" and an unrelated legal form error.
            throw new MonduException('Mondu: the customer has no company name. Mondu only accepts orders from companies, please add the company to the billing address or the customer.');
        }

        // Mondu treats the reference of an async order as its key and hands back the order it
        // already holds under a reference it has seen. Order numbers are not unique enough for
        // that (each sales channel may count on its own, a shop may reset its numbers), so the
        // order goes out under a reference of its own, the same way the hosted checkout does it,
        // and the order number follows through update_external_info once the buyer confirmed.
        $externalReferenceId = uniqid('M_SW6_');

        $payload = $this->payloadBuilder->build(
            $convertedCart,
            $event->getCart(),
            $salesChannelContext,
            $method,
            $externalReferenceId,
            $fields
        );

        $this->logger->info('mondu.INFO: Creating a Mondu async order for an order created in the admin', [
            'order_number' => $convertedCart['orderNumber'],
            'payload' => $payload,
        ]);

        $result = $this->monduClient->setSalesChannelId($salesChannelId)->createAsyncOrder($payload);
        $monduOrder = $result['body']['order'] ?? null;

        if ($result['status'] >= 300 || !isset($monduOrder['uuid'])) {
            $this->logger->error('mondu.ERROR: Mondu refused the order created in the admin', [
                'order_number' => $convertedCart['orderNumber'],
                'http_status' => $result['status'],
                'response' => $result['body'],
            ]);

            throw new MonduException($result['status'] === 0
                ? AsyncOrderErrorFormatter::UNREACHABLE
                : AsyncOrderErrorFormatter::format($result['body']));
        }

        $this->logger->info('mondu.INFO: Mondu async order created for an order created in the admin', [
            'order_number' => $convertedCart['orderNumber'],
            'mondu_uuid' => $monduOrder['uuid'],
            'mondu_state' => $monduOrder['state'] ?? null,
        ]);

        $convertedCart[OrderExtension::EXTENSION_NAME] = [
            OrderDataEntity::FIELD_ID => Uuid::randomHex(),
            OrderDataEntity::FIELD_REFERENCE_ID => $monduOrder['uuid'],
            OrderDataEntity::FIELD_ORDER_STATE => $monduOrder['state'] ?? 'processing',
            OrderDataEntity::FIELD_ORDER_FLOW => AsyncOrderStates::FLOW_ASYNC,
            OrderDataEntity::FIELD_EXTERNAL_REFERENCE_ID => $externalReferenceId,
            OrderDataEntity::FIELD_VIBAN => null,
            OrderDataEntity::FIELD_DURATION => 0,
            OrderDataEntity::FIELD_IS_SUCCESSFUL => false,
        ];

        $event->setConvertedCart($convertedCart);
    }

    /**
     * Applies the state Mondu already decided on while the order was being written.
     *
     * Runs after the order exists, see AsyncOrderStateSync. A failure here must not fail
     * the order the merchant just saved: the webhooks still deliver the state.
     */
    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        if (!$event->getContext()->getSource() instanceof AdminSalesChannelApiSource) {
            return;
        }

        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $event->getOrderId()));
        $criteria->addFilter(new EqualsFilter('orderFlow', AsyncOrderStates::FLOW_ASYNC));

        /** @var OrderDataEntity|null $orderData */
        $orderData = $this->orderDataRepository->search($criteria, $context)->first();
        if ($orderData === null) {
            return;
        }

        try {
            $this->stateSync->sync($orderData, $event->getOrder(), $context);
        } catch (\Throwable $e) {
            $this->logger->error('mondu.ERROR: Could not apply the Mondu state of an order created in the admin', [
                'order_number' => $event->getOrder()->getOrderNumber(),
                'mondu_uuid' => $orderData->getReferenceId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The term the form preselects, when the merchant left it as it was.
     *
     * The form saves its fields in the background, and an order saved while a save is still
     * on its way arrived without the preselected term although the form showed it. The term
     * the form preselects is known here as well, so it is applied the same way.
     *
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private function withDefaultNetTerm(array $fields, string $method, string $salesChannelId, ?string $countryCode): array
    {
        if (isset($fields[AsyncOrderFields::FIELD_NET_TERM])
            || !in_array(AsyncOrderFields::FIELD_NET_TERM, AsyncOrderFields::requiredFor($method), true)
        ) {
            return $fields;
        }

        $default = $this->paymentTermsService->pickDefault($this->paymentTermsService->getNetTerms(
            $salesChannelId,
            $countryCode,
            PaymentTermsService::SOURCE_ASYNC,
            $method
        ));

        if ($default !== null) {
            $fields[AsyncOrderFields::FIELD_NET_TERM] = (string) $default;
        }

        return $fields;
    }

    /**
     * Rejects the order before it reaches Mondu when a field the API needs is missing.
     *
     * @param array<string, string> $fields
     */
    private function validate(string $method, ?string $countryCode, array $fields): void
    {
        $category = $fields[AsyncOrderFields::FIELD_LEGAL_FORM_CATEGORY] ?? null;

        if ($category !== null && !in_array($category, AsyncOrderFields::LEGAL_FORM_CATEGORIES, true)) {
            throw new MonduException(sprintf('Mondu: unknown legal form category "%s".', $category));
        }

        $missing = [];
        foreach (AsyncOrderFields::requiredForOrder($method, $countryCode, $category) as $field) {
            if (!isset($fields[$field])) {
                $missing[] = self::label($field);
            }
        }

        if ($missing !== []) {
            throw new MonduException('Mondu: please fill the following required fields: ' . implode(', ', $missing));
        }

        $iban = $fields[AsyncOrderFields::FIELD_IBAN] ?? null;
        if ($iban !== null && !AsyncOrderFields::isValidIban($iban)) {
            throw new MonduException('Mondu: the IBAN is not valid. Please check it, e.g. DE02 1203 0000 0000 2020 51.');
        }

        $birthDate = $fields[AsyncOrderFields::FIELD_OWNER_BIRTH_DATE] ?? null;
        if ($birthDate !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);

            if (!$parsed || $parsed->format('Y-m-d') !== $birthDate) {
                throw new MonduException('Mondu: the owner date of birth must use the format YYYY-MM-DD.');
            }
            if ($parsed > new \DateTimeImmutable('today')) {
                throw new MonduException('Mondu: the owner date of birth cannot be in the future.');
            }
        }
    }

    private static function label(string $field): string
    {
        return match ($field) {
            AsyncOrderFields::FIELD_REGISTRATION_ID => 'Registration ID',
            AsyncOrderFields::FIELD_LEGAL_FORM_CATEGORY => 'Legal form category',
            AsyncOrderFields::FIELD_NET_TERM => 'Net term (days)',
            AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS => 'Number of installments',
            AsyncOrderFields::FIELD_IBAN => 'IBAN',
            AsyncOrderFields::FIELD_ACCOUNT_HOLDER => 'Account holder',
            AsyncOrderFields::FIELD_OWNER_FIRST_NAME => 'Owner first name',
            AsyncOrderFields::FIELD_OWNER_LAST_NAME => 'Owner last name',
            AsyncOrderFields::FIELD_OWNER_BIRTH_DATE => 'Owner date of birth',
            default => $field,
        };
    }
}
