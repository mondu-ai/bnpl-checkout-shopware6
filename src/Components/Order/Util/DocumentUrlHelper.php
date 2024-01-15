<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Util;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Storefront\Framework\Routing\Router;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DocumentUrlHelper
{
    private ConfigService $configService;
    private Router $router;

    /**
     * @param ConfigService $configService
     * @param Router $router
     */
    public function __construct(
        ConfigService $configService,
        Router $router
    ) {
        $this->configService = $configService;
        $this->router = $router;
    }

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
