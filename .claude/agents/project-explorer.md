# Project Explorer

You are exploring the Mondu BNPL payment plugin for Shopware 6.6.

## Quick Reference

- **Plugin Name**: `Mond1SW6`
- **Namespace**: `Mondu\MonduPayment`
- **Entry Point**: `src/Mond1SW6.php`
- **Shopware Version**: 6.6+ (constraint: `^6.5`)
- **PHP Version**: 8.2+
- **Symfony Version**: 6.4

## Shopware 6.6 Key Patterns

This plugin follows Shopware 6.6 conventions:
- **Routes**: PHP 8 attributes (`#[Route]`) instead of annotations
- **Repositories**: `EntityRepository` class (not interface)
- **Properties**: `readonly` keyword with constructor promotion
- **DI**: Symfony 6.4 service configuration
- **Admin**: Vue 3 Composition API components

## Directory Map

```
src/
├── Mond1SW6.php              # Plugin lifecycle (install/activate/update)
├── Bootstrap/                # Installation handlers
├── Command/                  # CLI commands (Mond1SW6:*)
├── Components/               # Main feature modules
│   ├── MonduApi/            # API client (MonduClient.php)
│   ├── PaymentMethod/       # 5 payment handlers
│   ├── Order/               # Order processing, DAL entities
│   ├── PluginConfig/        # Configuration (ConfigService)
│   ├── Webhooks/            # Webhook handling
│   ├── Checkout/            # Checkout flow
│   ├── StateMachine/        # State transitions
│   └── Events/              # Business events, Flow Builder
├── Services/                 # Decoratable abstract services
├── Migration/                # Database migrations (DAL)
└── Resources/
    ├── config/              # DI, routes, admin settings
    ├── views/               # Storefront Twig templates
    ├── app/administration/  # Admin Vue 3 components
    └── snippet/             # Translations
```

## Finding Things

| Looking for... | Location |
|----------------|----------|
| API calls to Mondu | `src/Components/MonduApi/Service/MonduClient.php` |
| Payment processing | `src/Components/PaymentMethod/PaymentHandler/` |
| Plugin settings | `src/Components/PluginConfig/Service/ConfigService.php` |
| Order data storage | `src/Components/Order/Model/OrderDataEntity.php` |
| DAL definitions | `src/Components/Order/Model/Definition/OrderDataDefinition.php` |
| Webhook handling | `src/Components/Webhooks/Controller/WebhooksController.php` |
| State changes | `src/Components/StateMachine/Subscriber/TransitionSubscriber.php` |
| Admin UI config | `src/Resources/config/config.xml` |
| Service definitions | `src/Resources/config/services.xml` |
| Flow Builder events | `src/Components/Events/` |

## Shopware 6.6 Specific Patterns in This Plugin

### Route Definition (PHP 8 Attributes)
```php
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhooksController extends StorefrontController
{
    #[Route(path: '/mondu/webhooks', name: 'mondu-payment.webhooks', methods: ['POST'])]
    public function process(Request $request, Context $context): Response
```

### Repository Injection
```php
public function __construct(
    private readonly EntityRepository $orderRepository,
    private readonly EntityRepository $orderDataRepository,
)
```

### Service Registration (services.xml)
```xml
<service id="...">
    <argument type="service" id="order.repository"/>
    <tag name="shopware.entity.definition"/>
</service>
```

## API Endpoints

- Production: `https://api.mondu.ai/api/v1`
- Sandbox: `https://api.demo.mondu.ai/api/v1`

## Key DAL Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `OrderDataDefinition` | `mondu_order_data` | Links Shopware orders to Mondu |
| `InvoiceDataDefinition` | `mondu_order_invoices` | Tracks invoices sent to Mondu |