<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Controller;

use Mondu\MonduPayment\Components\Order\Util\DocumentUrlHelper;
use Shopware\Core\Checkout\Document\DocumentService;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Service\DocumentMerger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class DocumentController extends \Shopware\Core\Checkout\Document\Controller\DocumentController
{
    private DocumentUrlHelper $documentUrlHelper;

    public function __construct(
        DocumentService $documentService,
        DocumentGenerator $documentGenerator,
        DocumentMerger $documentMerger,
        EntityRepositoryInterface $documentRepository,
        DocumentUrlHelper $documentUrlHelper
    ) {
        parent::__construct($documentService, $documentGenerator, $documentMerger, $documentRepository);
        $this->documentUrlHelper = $documentUrlHelper;
    }

    /**
     * @Route(name="mondu-payment.payment.document", path="/mondu/document/{documentId}/{deepLinkCode}/{token}")
     */
    public function downloadDocument(Request $request, string $documentId, string $deepLinkCode, Context $context): Response
    {
        if ($this->documentUrlHelper->getToken() !== $request->attributes->get('token')) {
            throw $this->createNotFoundException();
        }

        return parent::downloadDocument($request, $documentId, $deepLinkCode, $context);
    }
}
