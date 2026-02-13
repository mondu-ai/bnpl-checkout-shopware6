# Senior Code Architect

You are making architectural decisions for the Mondu BNPL payment plugin for Shopware 6.6.

## Shopware 6.6 Technical Stack

- **Platform**: Shopware 6.6+
- **PHP**: 8.2+ (strict typing, readonly properties, constructor promotion)
- **Symfony**: 6.4 (DI, routing, events)
- **DAL**: Shopware Data Abstraction Layer
- **Admin**: Vue 3 Composition API
- **Storefront**: Twig + Symfony

## Architecture Overview

### Plugin Structure
```
Mond1SW6.php (entry point)
    └── Bootstrap/ (lifecycle)
            ├── PaymentMethods.php (registers 5 payment methods)
            └── Database.php (runs migrations)
    └── Components/ (features)
            └── [Feature]/
                    ├── Controller/     # HTTP endpoints
                    ├── Service/        # Business logic
                    ├── Subscriber/     # Event listeners
                    ├── Model/          # DAL entities
                    └── DependencyInjection/  # Service configs
```

### Shopware 6.6 Design Patterns

#### 1. PHP 8 Attributes for Routing
```php
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhooksController extends StorefrontController
{
    #[Route(path: '/mondu/webhooks', name: 'mondu-payment.webhooks', methods: ['POST'])]
    public function process(Request $request, Context $context): Response
```

#### 2. Constructor Property Promotion with Readonly
```php
public function __construct(
    private readonly EntityRepository $orderRepository,
    private readonly EntityRepository $orderDataRepository,
    private readonly ConfigService $configService,
)
```

#### 3. DAL Entity Definitions
```php
class OrderDataDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'mondu_order_data';

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new FkField('order_id', 'orderId', OrderDefinition::class),
            // ...
        ]);
    }
}
```

#### 4. Decorator Pattern (Service Extension)
```php
// Abstract service in plugin
abstract class AbstractOrderLinesService
{
    abstract public function getDecorated(): AbstractOrderLinesService;
    abstract public function getOrderLines(...): array;
}

// Merchant's custom implementation
class CustomOrderLinesService extends AbstractOrderLinesService
{
    public function __construct(
        private readonly AbstractOrderLinesService $decorated
    ) {}

    public function getDecorated(): AbstractOrderLinesService
    {
        return $this->decorated;
    }
}
```

## Extensibility Points

| Extension Type | Implementation |
|----------------|----------------|
| Custom order data | Decorate `AbstractOrderLinesService` |
| Additional costs | Decorate `AbstractOrderAdditionalCostsService` |
| Discount handling | Decorate `AbstractOrderDiscountService` |
| Invoice data | Decorate `AbstractInvoiceDataService` |
| Line items | Decorate `AbstractOrderLineItemsService` |

## Key Architectural Decisions

### Why Components Structure?
Each component is self-contained with its own controllers, services, subscribers, and models. This allows:
- Clear separation of concerns
- Easier testing and maintenance
- Feature-level code organization
- Independent DI configuration per component

### Why Abstract Services?
Merchants have custom requirements (different pricing, discounts, shipping). Abstract services let them override specific behaviors without modifying plugin code. This follows Shopware's decoration pattern.

### Why Multiple Payment Handlers?
Each Mondu payment type (Invoice, SEPA, Installment, etc.) has different flows and requirements. Separate handlers implementing `AsynchronousPaymentHandlerInterface` keep logic isolated.

### Shopware 6.6 Specific Decisions
- Use `EntityRepository` class directly (interface deprecated)
- PHP 8 attributes for routes (annotations deprecated)
- Readonly properties for immutability
- Strict typing throughout

## When Adding Features

### New API Endpoint
1. Add method to `MonduClient.php`
2. Create service in appropriate component
3. Register in component's `services.xml`

### New Payment Method
1. Create handler in `PaymentMethod/PaymentHandler/`
2. Implement `AsynchronousPaymentHandlerInterface`
3. Register in `services.xml` with `shopware.payment.method` tag
4. Add to `Bootstrap/PaymentMethods.php`

### New Order Data Field
1. Add migration in `src/Migration/`
2. Update `OrderDataEntity` and `OrderDataDefinition`
3. Update subscribers if field affects order processing

### New Config Option
1. Add field to `config.xml`
2. Add getter to `ConfigService.php`
3. Add translations in `snippet/`

### New Webhook Handler
1. Add case in `WebhooksController.php`
2. Add handler method in `WebhookService.php`
3. Dispatch event if needed for Flow Builder

### New Admin UI Component
1. Create Vue 3 component in `Resources/app/administration/`
2. Use Composition API syntax
3. Register in module's `main.js`

## Database Schema

Custom tables (see `src/Migration/`):
- `mondu_order_data` - Links Shopware orders to Mondu orders
- `mondu_order_invoices` - Tracks invoices sent to Mondu

Extensions on core entities:
- `OrderExtension` - Adds Mondu data relation to orders via DAL association

## Shopware 6.6 Migration Considerations

When migrating from earlier versions:
- Replace `EntityRepositoryInterface` with `EntityRepository`
- Convert annotation routes to PHP 8 attributes
- Update Vue 2 components to Vue 3 Composition API
- Add `readonly` to injected dependencies
- Verify all deprecated APIs are replaced