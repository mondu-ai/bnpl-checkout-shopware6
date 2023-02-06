<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * MediaProvider Class.
 */
class MediaProvider
{
    /**
     * @var MediaService
     */
    private $mediaService;

    /** @var EntityRepositoryInterface */

    private $mediaRepository;

    /**
     * Constructs a `MediaProvider`
     *
     * @param MediaService $mediaService
     */
    public function __construct(MediaService $mediaService, EntityRepositoryInterface $mediaRepository)
    {
        $this->mediaService = $mediaService;
        $this->mediaRepository = $mediaRepository;
    }

    public function getLogoMediaId(Context $context): string
    {
        $existingMedia = $this->hasMediaAlreadyInstalled($context);

        if ($existingMedia) {
            return $existingMedia->getId();
        }

        $file = file_get_contents(dirname(__DIR__, 1).'/Resources/public/plugin.png');
        $mediaId = '';

        if ($file) {
            $mediaId = $this->mediaService->saveFile($file, 'png', 'image/png', 'mondu-payment-logo-v2', $context, 'payment_method', null, false);
        }

        return $mediaId;
    }

    public function removePaymentLogo(Context $context): void
    {
        $existingMedia = $this->hasMediaAlreadyInstalled($context);

        if ($existingMedia) {
            $this->mediaRepository->delete([['id' => $existingMedia->getId()]], $context);
        }
    }

    protected function hasMediaAlreadyInstalled(Context $context)
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                'mondu-payment-logo-v2'
            )
        );

        $media = $this->mediaRepository->search($criteria, $context)->first();

        return $media;
    }
}
