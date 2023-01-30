<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Command;

use Shopware\Core\Framework\Api\Controller\ApiController;
use Shopware\Core\Framework\Api\Response\Type\Api\JsonType;
use Shopware\Core\Framework\Api\Response\Type\Api\JsonApiType;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ActivatePaymentCommand extends Command
{
    protected static $defaultName = 'Mond1SW6:Activate:Payment';
    private ApiController $apiController;
    private JsonType $responseFactory;
    private JsonApiType $responseApiFactory;
    private Context $context;

    public function __construct(
        ApiController $apiController,
        JsonType $responseFactory
    )
    {
        parent::__construct();

        $this->apiController = $apiController;
        $this->responseFactory = $responseFactory;
        $this->context = Context::createDefaultContext();
    }

    protected function configure(): void
    {
        $this->setDescription('Adds API token to plugin configuration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $paymentMethods = $this->queryEntityData("payment-method", "app-payment-method");

        $monduPaymentIds = $this->filterIds($paymentMethods, function ($paymentMethod) {
            return str_contains($paymentMethod["distinguishableName"], "Mondu");
        });

        $salesChannels = $this->queryEntityData("sales-channel", "sales-channel");

        $storeFrontIds = $this->filterIds($salesChannels, function ($salesChannel) {
            return str_contains($salesChannel["name"], "Storefront");
        });

        foreach ($storeFrontIds as $storeFrontId) {
            $paymentMethodsIds = [];

            foreach ($monduPaymentIds as $id) {
                array_push($paymentMethodsIds, ["id" => $id]);
            }

            $request = (new Request)->create(
                "http://localhost/api/sales-channel/{$storeFrontId}",
                "PATCH",
                [
                    "id" => $storeFrontId,
                    "paymentMethods" => $paymentMethodsIds
                ]
            );

            $request->headers->set("Content-Type", "application/json");

            $response = $this->apiController->update($request, $this->context, $this->responseFactory, "sales-channel", $storeFrontId);
            if ($response->getStatusCode() != 204) {
                echo($response->getStatusCode());
                throw new \ErrorException("Unable to activate plugin in storefront");
            }
        }

        echo "Mondu activated as payment methods\n";

        return 0;
    }

    private function queryEntityData(
        $path,
        $entityName
    )
    {
        $request = (new Request)->create("http://localhost/api/search/{$path}", "POST", [
            "page" => 1,
            "limit" => 25,
            "term" => "",
            "total-count-mode" => 1
        ]);

        $response = $this->apiController->search($request, $this->context, $this->responseFactory, $path, $entityName);
        $responseContent = $response->getContent();

        return json_decode($responseContent, true)["data"];
    }

    private function filterIds(
        $data,
        $filter
    )
    {
        $filteredData = array_filter($data, $filter);

        $dataIds = array_map(function ($member) {
            return $member["id"];
        }, $filteredData);

        return array_values($dataIds);
    }
}
