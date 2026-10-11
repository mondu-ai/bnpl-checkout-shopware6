<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder\Controller;

use Mondu\MonduPayment\Bootstrap\PaymentMethods;
use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderFields;
use Mondu\MonduPayment\Components\AsyncOrder\Service\AsyncOrderFieldStorage;
use Mondu\MonduPayment\Components\AsyncOrder\Service\BackendOrdersService;
use Mondu\MonduPayment\Components\AsyncOrder\Service\PaymentTermsService;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Backs the Mondu fields on the admin order-create page.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AsyncOrderController extends AbstractController
{
    public function __construct(
        private readonly BackendOrdersService $backendOrdersService,
        private readonly PaymentTermsService $paymentTermsService,
        private readonly AsyncOrderFieldStorage $fieldStorage,
        private readonly EntityRepository $paymentMethodRepository,
        private readonly EntityRepository $customerAddressRepository
    ) {}

    /**
     * What the order-create page needs to offer Mondu: which methods to hide, and for the
     * selected method and billing address which fields to show, which are required and
     * which net terms the merchant holds.
     */
    #[Route(path: '/api/mondu/async-order/config', name: 'mondu-payment.async-order.config', methods: ['GET'])]
    public function config(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId') ?: null;
        $paymentMethodId = $request->query->get('paymentMethodId') ?: null;
        $billingAddressId = $request->query->get('billingAddressId') ?: null;
        $contextToken = $request->query->get('contextToken') ?: null;

        $monduMethods = $this->getMonduPaymentMethods($context);
        $active = $this->backendOrdersService->isActive($salesChannelId);

        $hiddenIds = [];
        $method = null;
        foreach ($monduMethods as $paymentMethod) {
            $monduName = MethodHelper::shortNameToMonduName($paymentMethod->getShortName());

            if (!$active || in_array($monduName, AsyncOrderFields::ADMIN_EXCLUDED_METHODS, true)) {
                $hiddenIds[] = $paymentMethod->getId();
            }
            if ($paymentMethod->getId() === $paymentMethodId) {
                $method = $monduName;
            }
        }

        $data = [
            'active' => $active,
            'monduPaymentMethodIds' => array_values($monduMethods->getIds()),
            'hiddenPaymentMethodIds' => $hiddenIds,
            'method' => $method,
        ];

        if (!$active || $method === null || in_array($method, AsyncOrderFields::ADMIN_EXCLUDED_METHODS, true)) {
            return new JsonResponse($data);
        }

        $countryCode = $this->getCountryCode($billingAddressId, $context);
        $netTerms = $this->paymentTermsService->getNetTerms($salesChannelId, $countryCode);

        return new JsonResponse($data + [
            'countryCode' => $countryCode,
            'visibleFields' => AsyncOrderFields::visibleFor($method),
            'requiredFields' => AsyncOrderFields::requiredFor($method),
            'ownerFields' => AsyncOrderFields::OWNER_FIELDS,
            'soleTraderCategory' => AsyncOrderFields::LEGAL_FORM_CATEGORY_SOLE_TRADER,
            'registrationIdRequired' => AsyncOrderFields::isRegistrationIdRequired($countryCode),
            'registrationIdHint' => AsyncOrderFields::registrationIdHint($countryCode),
            'legalFormCategories' => AsyncOrderFields::LEGAL_FORM_CATEGORIES,
            'installmentOptions' => AsyncOrderFields::INSTALLMENT_OPTIONS,
            'netTerms' => $netTerms,
            'defaultNetTerm' => $this->paymentTermsService->pickDefault($netTerms),
            'values' => $contextToken ? $this->fieldStorage->load($contextToken) : [],
        ]);
    }

    /**
     * Keeps the fields until the order is saved, see AsyncOrderFieldStorage.
     */
    #[Route(path: '/api/mondu/async-order/fields', name: 'mondu-payment.async-order.fields', methods: ['POST'])]
    public function saveFields(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $contextToken = is_array($data) ? ($data['contextToken'] ?? null) : null;
        $fields = is_array($data) && is_array($data['fields'] ?? null) ? $data['fields'] : [];

        if (!is_string($contextToken) || $contextToken === '') {
            return new JsonResponse(['message' => 'contextToken is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->fieldStorage->save($contextToken, $fields)) {
            return new JsonResponse(['message' => 'Unknown context token'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['fields' => $this->fieldStorage->load($contextToken)]);
    }

    private function getMonduPaymentMethods(Context $context): PaymentMethodCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('handlerIdentifier', array_keys(PaymentMethods::PAYMENT_METHODS)));

        /** @var PaymentMethodCollection $methods */
        $methods = $this->paymentMethodRepository->search($criteria, $context)->getEntities();

        return $methods->filter(static fn (PaymentMethodEntity $method) => MethodHelper::isMonduPayment($method));
    }

    private function getCountryCode(?string $billingAddressId, Context $context): ?string
    {
        if ($billingAddressId === null) {
            return null;
        }

        $criteria = new Criteria([$billingAddressId]);
        $criteria->addAssociation('country');

        /** @var CustomerAddressEntity|null $address */
        $address = $this->customerAddressRepository->search($criteria, $context)->first();

        return $address?->getCountry()?->getIso();
    }
}
