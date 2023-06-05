<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Controller;

use Mondu\MonduPayment\Components\Order\Util\DocumentUrlHelper;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class DocumentController extends \Shopware\Core\Checkout\Document\Controller\DocumentController
{
    /**
     * @Route(name="mondu-payment.payment.document", path="/mondu/document/{documentId}/{deepLinkCode}/{token}")
     */
    public function downloadDocument(Request $request, string $documentId, string $deepLinkCode, Context $context): Response
    {
        $documentUrlHelper = $this->container->get(DocumentUrlHelper::class);
        if ($documentUrlHelper->getToken() !== $request->attributes->get('token')) {
            throw $this->createNotFoundException();
        }

        return parent::downloadDocument($request, $documentId, $deepLinkCode, $context);
    }
}
