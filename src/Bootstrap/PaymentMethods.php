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
            'technicalName' => 'mondu_payment',
            'name' => 'Rechnungskauf (30 Tage)',
            'description' => '',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'Rechnungskauf (30 Tage)',
                    'description' => '',
                ],
                'en-GB' => [
                    'name' => 'Business net 30',
                    'description' => '',
                ],
                'nl-NL' => [
                    'name' => 'Factuur (30 dagen)',
                    'description' => '',
                ],
                'fr-FR' => [
                    'name' => 'Facture (30 jours)',
                    'description' => '',
                ]
            ],
        ],
        MonduSepaHandler::class => [
            'handlerIdentifier' => MonduSepaHandler::class,
            'technicalName' => 'mondu_sepa_payment',
            'name' => 'SEPA-Lastschrift (30 Tage)',
            'description' => '',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'SEPA-Lastschrift (30 Tage)',
                    'description' => '',
                ],
                'en-GB' => [
                    'name' => 'SEPA direct debit (30 days)',
                    'description' => '',
                ],
                'nl-NL' => [
                    'name' => 'SEPA automatische incasso (30 dagen)',
                    'description' => '',
                ],
                'fr-FR' => [
                    'name' => 'Prélèvement automatique SEPA (30 jours)',
                    'description' => '',
                ]
            ],
        ],
        MonduInstallmentHandler::class => [
            'handlerIdentifier' => MonduInstallmentHandler::class,
            'technicalName' => 'mondu_installment_payment',
            'name' => 'Ratenkauf - Bequem in Raten per Bankeinzug zahlen',
            'description' => '',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'Ratenkauf (3, 6, 12 Monaten)',
                    'description' => '',
                ],
                'en-GB' => [
                    'name' => 'Installments (3, 6, 12 months)',
                    'description' => '',
                ],
                'nl-NL' => [
                    'name' => 'Betaling in termijnen (3, 6, 12 maanden)',
                    'description' => '',
                ],
                'fr-FR' => [
                    'name' => 'Paiement échelonnés (3, 6, 12 mois)',
                    'description' => '',
                ]
            ],
        ],
        MonduInstallmentByInvoiceHandler::class => [
            'handlerIdentifier' => MonduInstallmentByInvoiceHandler::class,
            'technicalName' => 'mondu_installment_by_invoice_payment',
            'name' => 'Gesplittete Zahlungen - Ratenkauf per Banküberweisung',
            'description' => '',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'Ratenkauf (3, 6, 12 Monaten) UK',
                    'description' => '',
                ],
                'en-GB' => [
                    'name' => 'Business instalments (3, 6, 12)',
                    'description' => '',
                ],
                'nl-NL' => [
                    'name' => 'Betaling in termijnen (3, 6, 12 maanden) UK',
                    'description' => '',
                ],
                'fr-FR' => [
                    'name' => 'Paiement échelonnés (3, 6, 12 mois) UK',
                    'description' => '',
                ]
            ],
        ],
        MonduPayNowHandler::class => [
            'handlerIdentifier' => MonduPayNowHandler::class,
            'technicalName' => 'mondu_pay_now_payment',
            'name' => 'Echtzeitüberweisung',
            'description' => '',
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'Echtzeitüberweisung',
                    'description' => '',
                ],
                'en-GB' => [
                    'name' => 'Instant Pay',
                    'description' => '',
                ],
                'nl-NL' => [
                    'name' => 'Instant Pay',
                    'description' => '',
                ],
                'fr-FR' => [
                    'name' => 'Virement instantané',
                    'description' => '',
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
        $fileSaver = $this->container->get(\Shopware\Core\Content\Media\File\FileSaver::class);
        $mediaRepository = $this->container->get('media.repository');
        $mediaProvider = new MediaProvider($fileSaver, $mediaRepository);

        foreach (self::PAYMENT_METHODS as $handlerClass => $paymentMethod) {
            $logoFileName = self::PAYMENT_METHOD_LOGOS[$handlerClass] ?? null;

            if ($logoFileName) {
                $mediaId = $mediaProvider->getPaymentMethodLogoMediaId($logoFileName, $this->context);
            } else {
                $mediaId = $mediaProvider->getLogoMediaId($this->context);
            }

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
