<?php

namespace Mondu\MonduPayment\Components\Webhooks\Model;

class Webhook
{
    /**
     * @var string
     */
    private string $address;

    /**
     * @param $topic
     */
    public function __construct(
        private $topic
    ) {
        $this->address = $_SERVER['HTTP_ORIGIN'] . "/mondu/webhooks";
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
