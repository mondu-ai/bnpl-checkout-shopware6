# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Mondu BNPL (Buy Now, Pay Later) payment plugin for Shopware 6.6.

- **Plugin Name**: `Mond1SW6`
- **Namespace**: `Mondu\MonduPayment`
- **Shopware**: 6.6+ (constraint `^6.5`)
- **PHP**: 8.2+
- **Symfony**: 6.4

## Commands

### Release
```bash
./releaser.sh -v <new_version> -o <old_version> -c "keep"
```

### Development
```bash
docker-compose up                                              # Start Shopware 6.4.18.0 + MySQL
bin/console Mond1SW6:Test <api_token> <sandbox_mode>           # Test API credentials
bin/console Mond1SW6:Config:ApiToken <api_token> <sandbox_mode> # Set API token
bin/console Mond1SW6:Activate:Payment                          # Activate in storefronts
```

## Architecture

```
src/
├── Mond1SW6.php              # Entry point (install/activate/update)
├── Bootstrap/                # Lifecycle handlers
├── Components/               # Feature modules
│   ├── MonduApi/            # REST client (MonduClient.php)
│   ├── PaymentMethod/       # 5 payment handlers
│   ├── Order/               # Order processing, DAL entities
│   ├── PluginConfig/        # ConfigService
│   ├── Webhooks/            # Webhook handling
│   ├── Checkout/            # Checkout flow
│   ├── StateMachine/        # State transitions
│   └── Events/              # Business events
├── Services/                 # Decoratable abstract services
├── Migration/                # Database migrations
└── Resources/
    ├── config/              # services.xml, config.xml, routes.xml
    ├── snippet/             # Translations (en_GB, de_DE)
    └── app/administration/  # Admin Vue 3 components
```

## Shopware 6.6 Patterns

### Routes (PHP 8 Attributes)
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
    private readonly ConfigService $configService,
) {}
```

### Service Registration
```xml
<service id="Your\Service">
    <argument type="service" id="order.repository"/>
    <tag name="kernel.event_subscriber"/>
</service>
```

## Key Files

| Purpose | Location |
|---------|----------|
| API client | `src/Components/MonduApi/Service/MonduClient.php` |
| Plugin config | `src/Components/PluginConfig/Service/ConfigService.php` |
| Payment handlers | `src/Components/PaymentMethod/PaymentHandler/` |
| Order entity | `src/Components/Order/Model/OrderDataEntity.php` |
| Webhooks | `src/Components/Webhooks/Controller/WebhooksController.php` |
| DI config | `src/Resources/config/services.xml` |
| Admin settings | `src/Resources/config/config.xml` |

## Extensibility

Decoratable services in `src/Services/`:
- `AbstractOrderLinesService`
- `AbstractOrderAdditionalCostsService`
- `AbstractOrderDiscountService`
- `AbstractOrderLineItemsService`
- `AbstractInvoiceDataService`

See `src/Services/README.md` for decorator pattern documentation.

## API Endpoints

- Production: `https://api.mondu.ai/api/v1`
- Sandbox: `https://api.demo.mondu.ai/api/v1`

## Custom Commands

- `/.claude/commands/implement-task.md` — implement features
- `/.claude/commands/review-pr.md` — review pull requests

## Agent Documentation

See `.claude/agents/` for role-specific guides:
- `project-explorer.md` — codebase navigation
- `senior-code-architect.md` — architecture decisions
- `security-reviewer.md` — security review
- `github-pr-manager.md` — PR management
- `docs-maintainer.md` — documentation
