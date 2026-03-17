<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * MediaProvider Class.
 */
class MediaProvider
{
    private readonly string $resourcesPath;
    private readonly string $paymentLogosPath;

    public function __construct(
        private readonly FileSaver $fileSaver,
        private readonly EntityRepository $mediaRepository,
        string $pluginPath = ''
    ) {
        $pluginRoot = $this->resolvePluginRoot($pluginPath);
        $this->resourcesPath = $pluginRoot . '/src/Resources/public';
        $this->paymentLogosPath = $this->resourcesPath . '/images/de';
    }

    private function resolvePluginRoot(string $configuredPath): string
    {
        $candidate = rtrim($configuredPath, '/');
        if ($candidate !== '' && file_exists($candidate . '/src/Resources/public/plugin.png')) {
            return $candidate;
        }
        return dirname(__DIR__, 2);
    }

    public function getLogoMediaId(Context $context): string
    {
        $existingMedia = $this->hasMediaAlreadyInstalled($context);

        if ($existingMedia) {
            return $existingMedia->getId();
        }

        $logoPath = $this->resourcesPath . '/plugin.png';

        if (!file_exists($logoPath)) {
            return '';
        }

        $file = file_get_contents($logoPath);

        if ($file === false || empty($file)) {
            return '';
        }

        return $this->saveMediaFile($file, 'mondu-payment-logo-v2', $context);
    }

    public function getPaymentMethodLogoMediaId(string $logoFileName, Context $context): string
    {
        $mediaName = 'mondu-' . pathinfo($logoFileName, PATHINFO_FILENAME);
        $existingMedia = $this->hasMediaAlreadyInstalledByName($context, $mediaName);

        if ($existingMedia) {
            return $existingMedia->getId();
        }

        $logoPath = $this->paymentLogosPath . '/' . $logoFileName;

        if (!file_exists($logoPath)) {
            return $this->getLogoMediaId($context);
        }

        $file = file_get_contents($logoPath);

        if (!$file) {
            return '';
        }

        return $this->saveMediaFile($file, $mediaName, $context);
    }

    public function removePaymentLogo(Context $context): void
    {
        $existingMedia = $this->hasMediaAlreadyInstalled($context);

        if ($existingMedia) {
            $this->mediaRepository->delete([['id' => $existingMedia->getId()]], $context);
        }
    }

    private function saveMediaFile(string $fileContent, string $fileName, Context $context): string
    {
        $mediaId = Uuid::randomHex();
        $this->mediaRepository->create([['id' => $mediaId]], $context);

        $tempFile = tempnam(sys_get_temp_dir(), 'mondu_');
        file_put_contents($tempFile, $fileContent);

        try {
            $mediaFile = new MediaFile($tempFile, 'image/png', 'png', strlen($fileContent));
            $this->fileSaver->persistFileToMedia($mediaFile, $fileName, $mediaId, $context);
        } finally {
            @unlink($tempFile);
        }

        return $mediaId;
    }

    protected function hasMediaAlreadyInstalled(Context $context)
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('fileName', 'mondu-payment-logo-v2')
        );

        return $this->mediaRepository->search($criteria, $context)->first();
    }

    protected function hasMediaAlreadyInstalledByName(Context $context, string $mediaName)
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('fileName', $mediaName)
        );

        return $this->mediaRepository->search($criteria, $context)->first();
    }
}
