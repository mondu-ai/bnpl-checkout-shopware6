<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Controller;

use Mondu\MonduPayment\Components\Order\Util\DocumentUrlHelper;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Shopware\Core\Checkout\Document\DocumentService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Checkout\Document\Exception\InvalidDocumentException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * @RouteScope(scopes={"storefront"})
 */
class DocumentController extends AbstractController
{
    protected DocumentService $documentService;

    private EntityRepositoryInterface $documentRepository;

    /**
     * @internal
     */
    public function __construct(
        DocumentService $documentService,
        EntityRepositoryInterface $documentRepository
    ) {
        $this->documentService = $documentService;
        $this->documentRepository = $documentRepository;
    }

    /**
     * @Route(name="mondu-payment.payment.document", path="/mondu/document/{documentId}/{deepLinkCode}/{token}")
     */
    public function downloadDocument(Request $request, string $documentId, string $deepLinkCode, Context $context): Response
    {
        $documentUrlHelper = $this->container->get(DocumentUrlHelper::class);
        if ($documentUrlHelper->getToken() !== $request->attributes->get('token')) {
            throw $this->createNotFoundException();
        }

        return $this->generateDocument($request, $documentId, $deepLinkCode, $context);
    }

    private function generateDocument($request, $documentId, $deepLinkCode, $context) {
        $download = $request->query->getBoolean('download');

        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('id', $documentId),
            new EqualsFilter('deepLinkCode', $deepLinkCode),
        ]));
        $criteria->addAssociation('documentMediaFile');
        $criteria->addAssociation('documentType');

        $document = $this->documentRepository->search($criteria, $context)->get($documentId);

        if (!$document) {
            throw new InvalidDocumentException($documentId);
        }

        $generatedDocument = $this->documentService->getDocument($document, $context);

        return $this->createResponse(
            $generatedDocument->getFilename(),
            $generatedDocument->getFileBlob(),
            $download,
            $generatedDocument->getContentType()
        );
    }

    private function createResponse(string $filename, string $content, bool $forceDownload, string $contentType): Response
    {
        $response = new Response($content);

        $disposition = HeaderUtils::makeDisposition(
            $forceDownload ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $filename,
            // only printable ascii
            preg_replace('/[\x00-\x1F\x7F-\xFF]/', '_', $filename) ?? ''
        );

        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
