<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduSepaHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduInstallmentHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Mondu\MonduPayment\Components\Order\Model\Definition\OrderDataDefinition;
use Mondu\MonduPayment\Components\Invoice\InvoiceDataDefinition;
use Doctrine\DBAL\Connection;
use Mondu\MonduPayment\Bootstrap\MediaProvider;
use Shopware\Core\Framework\Context;

class PaymentMethods extends AbstractBootstrap
{
    public const PAYMENT_METHODS = [
        MonduHandler::class => [
            'handlerIdentifier' => MonduHandler::class,
            'name' => 'Rechnungskauf - Später per Banküberweisung bezahlen',
            'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/gdpr-notification-for-buyers/]hier[/url].',
            'afterOrderEnabled' => false,
            'translations' => [
                'de-DE' => [
                    'name' => 'Rechnungskauf - Später per Banküberweisung bezahlen',
                    'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/gdpr-notification-for-buyers/]hier[/url].',
                ],
                'en-GB' => [
                    'name' => 'Invoice - Pay later by bank transfer',
                    'description' => 'Information on the processing of your personal data by Mondu GmbH can be found [url=https://www.mondu.ai/de/gdpr-notification-for-buyers/]here[/url].',
                ],
                'nl-NL' => [
                    'name' => 'Aankoop op rekening - nu kopen, later betalen',
                    'description' => 'Informatie over de verwerking van uw persoonsgegevens door Mondu GmbH vindt u [url=https://mondu.ai/nl/gdpr-notification-for-buyers]hier[/url].'
                ],
                'fr-FR' => [
                    'name' => 'Achat sur facture - Payer plus tard par virement bancaire',
                    'description' => "Plus d'informations sur la façon dont Mondu GmbH traite vos données personnelles peuvent être trouvées [url=https://mondu.ai/fr/gdpr-notification-for-buyers]ici[/url]."
                ]
            ],
        ],
        MonduSepaHandler::class => [
            'handlerIdentifier' => MonduSepaHandler::class,
            'name' => 'SEPA - Später zahlen per Bankeinzug',
            'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
            'afterOrderEnabled' => false,
            'translations' => [
                'de-DE' => [
                    'name' => 'SEPA - Später zahlen per Bankeinzug',
                    'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
                ],
                'en-GB' => [
                    'name' => 'SEPA - Pay later by direct debit',
                    'description' => 'Information on the processing of your personal data by Mondu GmbH can be found [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]here[/url].',
                ],
                'nl-NL' => [
                    'name' => 'SEPA automatische incasso - nu kopen, later betalen',
                    'description' => 'Informatie over de verwerking van uw persoonsgegevens door Mondu GmbH vindt u [url=https://www.mondu.ai/nl/gdpr-notification-for-merchants/]hier[/url].'
                ],
                'fr-FR' => [
                    'name' => 'SEPA - Payer plus tard par prélèvement automatique SEPA',
                    'description' => "Plus d'informations sur la façon dont Mondu GmbH traite vos données personnelles peuvent être trouvées [url=https://mondu.ai/fr/gdpr-notification-for-buyers]ici[/url]."
                ]
            ],
        ],
        MonduInstallmentHandler::class => [
            'handlerIdentifier' => MonduInstallmentHandler::class,
            'name' => 'Ratenkauf - Bequem in Raten per Bankeinzug zahlen',
            'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
            'afterOrderEnabled' => false,
            'translations' => [
                'de-DE' => [
                    'name' => 'Ratenkauf - Bequem in Raten per Bankeinzug zahlen',
                    'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
                ],
                'en-GB' => [
                    'name' => 'Split payments - Pay conveniently in instalments by direct debit',
                    'description' => 'Information on the processing of your personal data by Mondu GmbH can be found [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]here[/url].',
                ],
                'nl-NL' => [
                    'name' => 'Gespreid betalen, betaal gemakkelijk in termijnen via automatische incasso',
                    'description' => 'Informatie over de verwerking van uw persoonsgegevens door Mondu GmbH vindt u [url=https://www.mondu.ai/nl/gdpr-notification-for-merchants/]hier[/url].'
                ],
                'fr-FR' => [
                    'name' => 'Paiement échelonné - Payer confortablement en plusieurs fois par prélèvement automatique',
                    'description' => "Plus d'informations sur la façon dont Mondu GmbH traite vos données personnelles peuvent être trouvées [url=https://mondu.ai/fr/gdpr-notification-for-buyers]ici[/url]."
                ]
            ],
        ],
    ];

    /**
     * @var EntityRepository
     */
    private $paymentRepository;

    public function injectServices(): void
    {
        $this->paymentRepository = $this->container->get('payment_method.repository');
    }

    public function update(): void
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $this->upsertPaymentMethod($paymentMethod);
        }

        $this->updatePaymentMethodImage();
        
    }

    public function install(): void
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $this->upsertPaymentMethod($paymentMethod);
        }

        $this->setActiveFlags(false);
    }

    public function uninstall(bool $keepUserData = false): void
    {
        $this->setActiveFlags(false);
    }

    public function activate(): void
    {
        $this->setActiveFlags(true);

        $this->updatePaymentMethodImage();
    }

    public function deactivate(): void
    {
        $this->setActiveFlags(false);
    }

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

    protected function updatePaymentMethodImage()
    {
        $mediaProvider = $this->container->get(MediaProvider::class);

        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $mediaId = $mediaProvider->getLogoMediaId($this->context);

            $paymentSearchResult = $this->paymentRepository->search(
                (
                (new Criteria())
                ->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethod['handlerIdentifier']))
                ->setLimit(1)
            ),
                $this->context
            );

            $paymentMethodData = [
            'id' => $paymentSearchResult->first()->getId(),
            'mediaId' => $mediaId
          ];

            $this->paymentRepository->update([$paymentMethodData], $this->context);
        }
    }
}
