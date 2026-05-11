# Review Pull Request

Review the pull request for the Mondu BNPL payment plugin (Shopware 6.6).

## PR Reference

$ARGUMENTS

## Review Checklist

### Shopware 6.6 Compatibility

- [ ] Uses `EntityRepository` (not deprecated `EntityRepositoryInterface`)
- [ ] Routes use PHP 8 attributes (`#[Route]`), not annotations
- [ ] Constructor property promotion with `readonly` where appropriate
- [ ] No deprecated Shopware 6.5 APIs used
- [ ] Admin components use Vue 3 Composition API (if applicable)

### Code Quality

- [ ] Strict typing (`declare(strict_types=1)`)
- [ ] Proper namespace under `Mondu\MonduPayment\`
- [ ] Services registered in `services.xml`
- [ ] No hardcoded values (use ConfigService)
- [ ] Proper error handling and logging

### Security

- [ ] No sensitive data in logs (API keys, tokens)
- [ ] Webhook signatures validated
- [ ] User input sanitized
- [ ] DAL used for database operations (no raw SQL)
- [ ] Route scopes properly defined (`storefront`, `api`)

### Translations

- [ ] New UI strings added to `snippet/en_GB/`
- [ ] German translations in `snippet/de_DE/`
- [ ] Config labels in `config.xml` have both languages

### Database

- [ ] New fields have migrations in `src/Migration/`
- [ ] Migration class name follows pattern: `Migration{timestamp}{Description}`
- [ ] DAL definitions updated if schema changes

### Documentation

- [ ] README.md updated for new features/commands
- [ ] Services/README.md updated for new decoratable services
- [ ] PHPDoc blocks for public methods

## Review Output

Provide:
1. **Summary**: Brief description of changes
2. **Compatibility**: Shopware 6.6 compliance status
3. **Issues**: List of problems found (if any)
4. **Suggestions**: Improvements or optimizations
5. **Verdict**: Approve / Request Changes / Needs Discussion