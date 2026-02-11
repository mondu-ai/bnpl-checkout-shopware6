<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduInstallmentByInvoiceHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduSepaHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduInstallmentHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduPayNowHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class PaymentMethods extends AbstractBootstrap
{
    public const PAYMENT_METHOD_LOGOS = [
        MonduHandler::class => 'invoice_white_rectangle.png',
        MonduSepaHandler::class => 'sepa_white_rectangle.png',
        MonduInstallmentHandler::class => 'installments_white_rectangle.png',
        MonduInstallmentByInvoiceHandler::class => 'installments_white_rectangle.png',
        MonduPayNowHandler::class => 'instant_pay_white_rectangle.png',
    ];

    public const PAYMENT_METHODS = [
        MonduHandler::class => [
            'handlerIdentifier' => MonduHandler::class,
            'name' => 'Rechnungskauf (30 Tage)',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'Rechnungskauf (30 Tage)'
                ],
                'en-GB' => [
                    'name' => 'Business net 30'
                ],
                'nl-NL' => [
                    'name' => 'Factuur (30 dagen)'
                ],
                'fr-FR' => [
                    'name' => 'Facture (30 jours)'
                ]
            ],
        ],
        MonduSepaHandler::class => [
            'handlerIdentifier' => MonduSepaHandler::class,
            'name' => 'SEPA-Lastschrift (30 Tage)',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'SEPA-Lastschrift (30 Tage)'
                ],
                'en-GB' => [
                    'name' => 'SEPA direct debit (30 days)'
                ],
                'nl-NL' => [
                    'name' => 'SEPA automatische incasso (30 dagen)'
                ],
                'fr-FR' => [
                    'name' => 'Prélèvement automatique SEPA (30 jours)'
                ]
            ],
        ],
        MonduInstallmentHandler::class => [
            'handlerIdentifier' => MonduInstallmentHandler::class,
            'name' => 'Ratenkauf - Bequem in Raten per Bankeinzug zahlen',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'Ratenkauf (3, 6, 12 Monaten)'
                ],
                'en-GB' => [
                    'name' => 'Installments (3, 6, 12 months)'
                ],
                'nl-NL' => [
                    'name' => 'Betaling in termijnen (3, 6, 12 maanden)'
                ],
                'fr-FR' => [
                    'name' => 'Paiement échelonnés (3, 6, 12 mois)'
                ]
            ],
        ],
        MonduInstallmentByInvoiceHandler::class => [
            'handlerIdentifier' => MonduInstallmentByInvoiceHandler::class,
            'name' => 'Gesplittete Zahlungen - Ratenkauf per Banküberweisung',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'Ratenkauf (3, 6, 12 Monaten) UK'
                ],
                'en-GB' => [
                    'name' => 'Business instalments (3, 6, 12)'
                ],
                'nl-NL' => [
                    'name' => 'Betaling in termijnen (3, 6, 12 maanden) UK'
                ],
                'fr-FR' => [
                    'name' => 'Paiement échelonnés (3, 6, 12 mois) UK'
                ]
            ],
        ],
        MonduPayNowHandler::class => [
            'handlerIdentifier' => MonduPayNowHandler::class,
            'name' => 'Echtzeitüberweisung',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'Echtzeitüberweisung'
                ],
                'en-GB' => [
                    'name' => 'Instant Pay'
                ],
                'nl-NL' => [
                    'name' => 'Instant Pay'
                ],
                'fr-FR' => [
                    'name' => 'Virement instantané'
                ]
            ],
        ],
    ];

    /**
     * @var EntityRepository
     */
    private EntityRepository $paymentRepository;

    /**
     * @return void
     */
    public function injectServices(): void
    {
        $this->paymentRepository = $this->container->get('payment_method.repository');
    }

    /**
     * @return void
     */
    public function update(): void
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $this->upsertPaymentMethod($paymentMethod);
        }

        $this->updatePaymentMethodImage();

    }

    /**
     * @return void
     */
    public function install(): void
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $this->upsertPaymentMethod($paymentMethod);
        }

        $this->setActiveFlags(false);
    }

    /**
     * @param  bool  $keepUserData
     *
     * @return void
     */
    public function uninstall(bool $keepUserData = false): void
    {
        $this->setActiveFlags(false);
    }

    /**
     * @return void
     */
    public function activate(): void
    {
        $this->setActiveFlags(true);

        $this->updatePaymentMethodImage();
    }

    /**
     * @return void
     */
    public function deactivate(): void
    {
        $this->setActiveFlags(false);
    }

    /**
     * @param  array  $paymentMethod
     *
     * @return void
     */
    protected function upsertPaymentMethod(array $paymentMethod): void
    {
        $paymentSearchResult = $this->paymentRepository->search(
            (
                (new Criteria())
                ->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethod['handlerIdentifier']))
                ->setLimit(1)
            ),
            $this->context
        );

        /** @var PaymentMethodEntity|null $paymentEntity */
        $paymentEntity = $paymentSearchResult->first();
        if ($paymentEntity) {
            $paymentMethod['id'] = $paymentEntity->getId();
        }

        $paymentMethod['pluginId'] = $this->plugin->getId();
        $this->paymentRepository->upsert([$paymentMethod], $this->context);
    }

    /**
     * @param  bool  $activated
     *
     * @return void
     */
    protected function setActiveFlags(bool $activated): void
    {
        $paymentEntities = $this->paymentRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('pluginId', $this->plugin->getId())),
            $this->context
        );

        $updateData = array_map(static function (PaymentMethodEntity $entity) use ($activated) {
            return [
                'id' => $entity->getId(),
                'active' => $activated,
            ];
        }, $paymentEntities->getElements());

        $this->paymentRepository->update(array_values($updateData), $this->context);
    }

    /**
     * @return void
     */
    protected function updatePaymentMethodImage(): void
    {
        $mediaProvider = $this->container->get(MediaProvider::class);

        foreach (self::PAYMENT_METHODS as $handlerClass => $paymentMethod) {
            // Get specific logo for this payment method
            $logoFileName = self::PAYMENT_METHOD_LOGOS[$handlerClass] ?? null;
            
            if ($logoFileName) {
                $mediaId = $mediaProvider->getPaymentMethodLogoMediaId($logoFileName, $this->context);
            } else {
                // Fallback to default logo
                $mediaId = $mediaProvider->getLogoMediaId($this->context);
            }

            // Skip update if mediaId is empty to avoid UUID validation error
            if (empty($mediaId)) {
                continue;
            }

            $paymentSearchResult = $this->paymentRepository->search(
                (
                (new Criteria())
                ->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethod['handlerIdentifier']))
                ->setLimit(1)
            ),
                $this->context
            );

            if ($paymentSearchResult->first()) {
                $paymentMethodData = [
                    'id' => $paymentSearchResult->first()->getId(),
                    'mediaId' => $mediaId
                ];
                $this->paymentRepository->update([$paymentMethodData], $this->context);
            }
        }
    }
}
