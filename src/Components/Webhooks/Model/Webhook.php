<?php

namespace Mondu\MonduPayment\Components\Webhooks\Model;

use Mondu\MonduPayment\Components\Webhooks\Service\ShopUrlService;

class Webhook
{
    /**
     * @var string
     */
    private string $address;

    /**
     * @param $topic
     * @param ShopUrlService $shopUrlService
     * @param string|null $salesChannelId
     */
    public function __construct(
        private $topic,
        private ShopUrlService $shopUrlService,
        private ?string $salesChannelId = null
    ) {
        $this->address = $this->shopUrlService->getShopUrl($this->salesChannelId) . "/mondu/webhooks";
    }

    public function getTopic(): string
    {
      return $this->topic;
    }

    public function getAddress(): string
    {
      return $this->address;
    }

    public function getData(): array
    {
        return [
            'topic' => $this->getTopic(),
            'address' => $this->getAddress()
        ];
    }
}
