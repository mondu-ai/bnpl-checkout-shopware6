<?php

namespace Mondu\MonduPayment\Components\Webhooks\Model;

/**
 * Webhook Class
 */
class Webhook
{
    private string $topic;
    private string $address;

    public function __construct($topic)
    {
      $this->topic = $topic;
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

    public function getData()
    {
      return [
        'topic' => $this->getTopic(),
        'address' => $this->getAddress()
      ];
    }
}
