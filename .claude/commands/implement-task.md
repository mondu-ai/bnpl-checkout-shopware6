# Implement Task

Implement the following task for the Mondu BNPL payment plugin (Shopware 6.6).

## Task Description

$ARGUMENTS

## Implementation Guidelines

### Shopware 6.6 Requirements

- PHP 8.2+ with strict typing
- Use `EntityRepository` (not `EntityRepositoryInterface`)
- PHP 8 attributes for routes: `#[Route(path: '...', name: '...', methods: ['...'])]`
- Constructor property promotion with `readonly` keyword
- Symfony 6.4 dependency injection

### Code Style

```php
<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\...;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class ExampleService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly ConfigService $configService,
    ) {}
}
```

### Before Implementation

1. Explore related code in `src/Components/`
2. Check existing patterns in similar features
3. Verify DAL entity definitions if working with data
4. Review `services.xml` for dependency injection patterns

### After Implementation

1. Register new services in appropriate `DependencyInjection/services.xml`
2. Add translations to `src/Resources/snippet/` (en_GB and de_DE)
3. Update `config.xml` if adding configuration options
4. Create migration if modifying database schema

### File Locations

| Type | Location |
|------|----------|
| Controllers | `src/Components/[Feature]/Controller/` |
| Services | `src/Components/[Feature]/Service/` |
| Subscribers | `src/Components/[Feature]/Subscriber/` |
| DAL Entities | `src/Components/[Feature]/Model/` |
| Migrations | `src/Migration/` |
| DI Config | `src/Components/[Feature]/DependencyInjection/` |

### Testing

After implementation:
1. Verify plugin activates without errors
2. Test the feature in Shopware admin/storefront
3. Check logs for any warnings (`var/log/`)