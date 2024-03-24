<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Util;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Storefront\Framework\Routing\Router;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DocumentUrlHelper
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly Router $router
    ) {}

    public function generateRouteForDocument(DocumentEntity $document): string
    {
        return $this->router->generate('mondu-payment.payment.document', [
            'documentId' => $document->getId(),
            'deepLinkCode' => $document->getDeepLinkCode(),
            'token' => $this->getToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function getToken(): string
    {
        return sha1(md5(implode('', [
            $this->configService->getApiToken()
        ])));
    }
}
