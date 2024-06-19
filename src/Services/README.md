## Services

### How to decorate a service

1. Create a class that extends the abstract class of the service you want to decorate

```php
<?php declare(strict_types=1);

namespace Your\Namespace;

class OrderAdditionalCostsServiceDecorated extends AbstractOrderAdditionalCostsService
{
    public function __construct(private readonly AbstractOrderAdditionalCostsService $decorated) {}

    public function getDecorated(): AbstractOrderAdditionalCostsService
    {
        return $this->decorated;
    }
    
    public function getAdditionalCostsCents(OrderEntity $order, Context $context): int
    {
        $originalResult = $this->getDecorated()->getAdditionalCostsCents(); // you can get the original result like so.
        return (int) ($order->getAdditionalCosts() * 100); // Price in cents
    }
}
```

2. Map the decorator class in services.xml  

```xml
<?xml version="1.0" ?>

<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">
    <services>
        <defaults autowire="true"/>

        <service id="Your\Namespace\OrderAdditionalCostsServiceDecorated" decorates="Mondu\MonduPayment\Services\OrderServices\AbstractOrderAdditionalCostsService">
            <argument type="service" id=".inner" />
        </service>
    </services>
</container>

```