<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Util;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class DocumentUrlHelper
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly RouterInterface $router
    ) {}

    public function generateRouteForDocument(DocumentEntity $document): string
    {
        return $this->router->generate('mondu-payment.payment.document', [
            'documentId' => $document->getId(),
            'deepLinkCode' => $document->getDeepLinkCode(),
            'token' => $this->getTokenForDocument($document->getId(), $document->getDeepLinkCode()),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function getTokenForDocument(string $documentId, string $deepLinkCode): string
    {
        return hash_hmac('sha256', $documentId . ':' . $deepLinkCode, $this->configService->getApiToken());
    }

    /** @deprecated Use getTokenForDocument() instead */
    public function getToken(): string
    {
        return sha1(md5(implode('', [
            $this->configService->getApiToken()
        ])));
    }
}
