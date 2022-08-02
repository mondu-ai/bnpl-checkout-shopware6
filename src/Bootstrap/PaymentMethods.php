<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduSepaHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
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
            'name' => 'Rechnungskauf - jetzt kaufen, später bezahlen',
            'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
            'afterOrderEnabled' => false,
            'translations' => [
                'de-DE' => [
                    'name' => 'Rechnungskauf - jetzt kaufen, später bezahlen',
                    'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
                ],
                'en-GB' => [
                    'name' => 'Rechnungskauf - jetzt kaufen, später bezahlen',
                    'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
                ],
            ],
        ],
        MonduSepaHandler::class => [
            'handlerIdentifier' => MonduSepaHandler::class,
            'name' => 'SEPA-Lastschrift - jetzt kaufen, später per Bankeinzug bezahlen',
            'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
            'afterOrderEnabled' => false,
            'translations' => [
                'de-DE' => [
                    'name' => 'SEPA-Lastschrift - jetzt kaufen, später per Bankeinzug bezahlen',
                    'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
                ],
                'en-GB' => [
                    'name' => 'SEPA-Lastschrift - jetzt kaufen, später per Bankeinzug bezahlen',
                    'description' => 'Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie [url=https://www.mondu.ai/de/datenschutzgrundverordnung-kaeufer/]hier[/url].',
                ],
            ],
        ],
    ];

    /**
     * @var EntityRepositoryInterface
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
        // Keep active flags as they are
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
            $this->defaultContext
        );

        /** @var PaymentMethodEntity|null $paymentEntity */
        $paymentEntity = $paymentSearchResult->first();
        if ($paymentEntity) {
            $paymentMethod['id'] = $paymentEntity->getId();
        }

        $paymentMethod['pluginId'] = $this->plugin->getId();
        $this->paymentRepository->upsert([$paymentMethod], $this->defaultContext);
    }

    protected function setActiveFlags(bool $activated): void
    {
        $paymentEntities = $this->paymentRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('pluginId', $this->plugin->getId())),
            $this->defaultContext
        );

        $updateData = array_map(static function (PaymentMethodEntity $entity) use ($activated) {
            return [
                'id' => $entity->getId(),
                'active' => $activated,
            ];
        }, $paymentEntities->getElements());

        $this->paymentRepository->update(array_values($updateData), $this->defaultContext);
    }

    protected function updatePaymentMethodImage()
    {
        $mediaProvider = $this->container->get(MediaProvider::class);

        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $mediaId = $mediaProvider->getLogoMediaId($this->defaultContext);

            $paymentSearchResult = $this->paymentRepository->search(
                (
                (new Criteria())
                ->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethod['handlerIdentifier']))
                ->setLimit(1)
            ),
                $this->defaultContext
            );

            $paymentMethodData = [
            'id' => $paymentSearchResult->first()->getId(),
            'mediaId' => $mediaId
          ];

            $this->paymentRepository->update([$paymentMethodData], $this->defaultContext);
        }
    }
}
