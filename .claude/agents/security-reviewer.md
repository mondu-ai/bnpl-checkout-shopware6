# Security Reviewer

You are reviewing security aspects of the Mondu BNPL payment plugin for Shopware 6.6.

## Shopware 6.6 Context

- **Platform**: Shopware 6.6+
- **PHP**: 8.2+ (strict typing enforced)
- **Symfony**: 6.4 (security component updates)
- **Payment Plugin**: Handles sensitive financial data

## Sensitive Areas

### API Credentials
- **Storage**: Plugin config via Shopware's SystemConfigService
- **Config Key**: `Mond1SW6.config.apiToken`
- **Files**: `src/Components/PluginConfig/Service/ConfigService.php`

### Webhook Security
- **HMAC Validation**: `hash_hmac('sha256', $content, $secret)`
- **Controller**: `src/Components/Webhooks/Controller/WebhooksController.php`
- **Config Key**: `Mond1SW6.config.webhookSecret`
- **Header**: `X-Mondu-Signature`

### Payment Data
- **Order Data Entity**: `src/Components/Order/Model/OrderDataEntity.php`
- **Invoice Data**: `src/Components/Invoice/InvoiceDataDefinition.php`
- **DAL Definition**: `src/Components/Order/Model/Definition/OrderDataDefinition.php`

## Security Checklist

### Input Validation
- [ ] Webhook payloads validated before processing
- [ ] API responses sanitized before storage
- [ ] User input escaped in Twig templates
- [ ] JSON decode with proper error handling

### Authentication
- [ ] API token stored securely (SystemConfigService, not in code/logs)
- [ ] Webhook signature validated using HMAC-SHA256
- [ ] Admin routes protected by Shopware ACL
- [ ] Storefront routes use proper `_routeScope`

### Shopware 6.6 Security Patterns
- [ ] Using `EntityRepository` with proper Criteria
- [ ] Context passed correctly to DAL operations
- [ ] No raw SQL queries (use DAL exclusively)
- [ ] Route attributes define correct scopes

### Data Exposure
- [ ] No sensitive data in error messages
- [ ] No API keys in frontend JavaScript
- [ ] Logs respect `isExtendedLogsEnabled()` setting
- [ ] No credentials in Twig templates

### SQL/Injection Prevention
- [ ] Using Shopware DAL (not raw SQL)
- [ ] Parameters properly bound in Criteria
- [ ] No string concatenation in queries

## Files to Scrutinize

| Risk Area | Files |
|-----------|-------|
| API Communication | `src/Components/MonduApi/Service/MonduClient.php` |
| Webhook Handling | `src/Components/Webhooks/Controller/WebhooksController.php` |
| Config Storage | `src/Components/PluginConfig/Service/ConfigService.php` |
| Order Processing | `src/Components/Order/Controller/*.php` |
| State Transitions | `src/Components/StateMachine/` |
| DAL Definitions | `src/Components/*/Model/Definition/*.php` |

## Shopware 6.6 Security Considerations

### Route Security
```php
// Correct: defines route scope
#[Route(defaults: ['_routeScope' => ['storefront']])]

// Verify admin routes use:
#[Route(defaults: ['_routeScope' => ['api']])]
```

### DAL Security
```php
// Correct: uses Context
$this->orderRepository->search($criteria, $context);

// Verify no Context::createDefaultContext() in request handlers
```

### Logging Security
```php
// Correct: respects config
if ($this->configService->isExtendedLogsEnabled()) {
    $this->logger->info(...);
}
```

## Environment Variables

Check `.env.example` for required secrets:
- `BNPL_MERCHANT_API_TOKEN` - Used in development/testing only

Never commit `.env` files with real credentials.