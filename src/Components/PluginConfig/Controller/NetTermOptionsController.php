<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PluginConfig\Controller;

use Mondu\MonduPayment\Components\AsyncOrder\Service\PaymentTermsService;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Options for the net term fields of the plugin config: the terms the merchant holds in
 * Mondu for the payment method, instead of a fixed list.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class NetTermOptionsController extends AbstractController
{
    public function __construct(
        private readonly PaymentTermsService $paymentTermsService
    ) {}

    #[Route(path: '/api/mondu/config/net-terms', name: 'mondu-payment.config.net-terms', methods: ['GET'])]
    public function netTerms(Request $request): JsonResponse
    {
        $paymentMethod = (string) $request->query->get('paymentMethod', '');
        if (!isset(ConfigService::AVAILABLE_NET_TERMS_KEYS[$paymentMethod])) {
            return new JsonResponse(['message' => 'Unknown payment method'], Response::HTTP_BAD_REQUEST);
        }

        $salesChannelId = $request->query->get('salesChannelId') ?: null;

        return new JsonResponse([
            // null: Mondu could not be asked, e.g. no valid API token yet
            'netTerms' => $this->paymentTermsService->getCheckoutNetTermOptions($salesChannelId, $paymentMethod),
        ]);
    }
}
