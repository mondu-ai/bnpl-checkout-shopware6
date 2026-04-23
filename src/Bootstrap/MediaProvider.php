<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * MediaProvider Class.
 */
class MediaProvider
{
    private readonly string $resourcesPath;
    private readonly string $paymentLogosPath;

    public function __construct(
        private readonly MediaService $mediaService,
        private readonly EntityRepository $mediaRepository
    ) {
        // Resolve the plugin root from this file's location so the path works
        // regardless of how the plugin is installed: composer (vendor/mondu/…),
        // manual upload (custom/plugins/Mond1SW6/), symlink, etc. Hard-coding
        // %kernel.project_dir%/custom/plugins/Mond1SW6 would fail for every
        // non-custom-plugins install.
        $pluginRoot = \dirname(__DIR__, 2);
        $this->resourcesPath = $pluginRoot . '/src/Resources/public';
        $this->paymentLogosPath = $this->resourcesPath . '/images/de';
    }

    /**
     * @param  Context  $context
     *
     * @return string
     */
    public function getLogoMediaId(Context $context): string
    {
        $existingMedia = $this->hasMediaAlreadyInstalled($context);

        if ($existingMedia) {
            return $existingMedia->getId();
        }

        $file = file_get_contents($this->resourcesPath . '/plugin.png');
        $mediaId = '';

        if ($file) {
            $mediaId = $this->mediaService->saveFile($file, 'png', 'image/png', 'mondu-payment-logo-v2', $context, 'payment_method', null, false);
        }

        return $mediaId;
    }

    /**
     * Get media ID for specific payment method logo
     *
     * @param  string  $logoFileName
     * @param  Context  $context
     *
     * @return string
     */
    public function getPaymentMethodLogoMediaId(string $logoFileName, Context $context): string
    {
        $mediaName = 'mondu-' . pathinfo($logoFileName, PATHINFO_FILENAME);
        $existingMedia = $this->hasMediaAlreadyInstalledByName($context, $mediaName);

        if ($existingMedia) {
            return $existingMedia->getId();
        }

        $logoPath = $this->paymentLogosPath . '/' . $logoFileName;
        
        if (!file_exists($logoPath)) {
            // Fallback to default logo if specific logo not found
            return $this->getLogoMediaId($context);
        }

        $file = file_get_contents($logoPath);
        $mediaId = '';

        if ($file) {
            $mediaId = $this->mediaService->saveFile($file, 'png', 'image/png', $mediaName, $context, 'payment_method', null, false);
        }

        return $mediaId;
    }

    /**
     * @param  Context  $context
     *
     * @return void
     */
    public function removePaymentLogo(Context $context): void
    {
        $existingMedia = $this->hasMediaAlreadyInstalled($context);

        if ($existingMedia) {
            $this->mediaRepository->delete([['id' => $existingMedia->getId()]], $context);
        }
    }

    /**
     * @param  Context  $context
     *
     * @return \Shopware\Core\Framework\DataAbstractionLayer\Entity|null
     */
    protected function hasMediaAlreadyInstalled(Context $context)
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                'mondu-payment-logo-v2'
            )
        );

        return $this->mediaRepository->search($criteria, $context)->first();
    }

    /**
     * Check if media already installed by custom name
     *
     * @param  Context  $context
     * @param  string  $mediaName
     *
     * @return \Shopware\Core\Framework\DataAbstractionLayer\Entity|null
     */
    protected function hasMediaAlreadyInstalledByName(Context $context, string $mediaName)
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                $mediaName
            )
        );

        return $this->mediaRepository->search($criteria, $context)->first();
    }
}
