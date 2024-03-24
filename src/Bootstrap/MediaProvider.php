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
    /**
     * Constructs a `MediaProvider`
     *
     * @param  MediaService  $mediaService
     * @param  EntityRepository  $mediaRepository
     */
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly EntityRepository $mediaRepository
    ) {}

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

        $file = file_get_contents(dirname(__DIR__, 1).'/Resources/public/plugin.png');
        $mediaId = '';

        if ($file) {
            $mediaId = $this->mediaService->saveFile($file, 'png', 'image/png', 'mondu-payment-logo-v2', $context, 'payment_method', null, false);
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
}
