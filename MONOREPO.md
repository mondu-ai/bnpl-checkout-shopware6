# Mondu Shopware 6 — monorepo layout

One git repository, **two** Community-Store plugin releases (`mondu/shopware66-payment`,
`mondu/shopware6-payment`). All business logic lives in one place; the release
pipeline produces two self-contained ZIPs with the shared code **physically
inlined** so each plugin looks standalone to merchants.

```
mondu-shopware6/
├── core/                             ← single source of truth for PHP
│   └── src/
│       ├── Mond1SW6/…                (namespace Mondu\MonduPayment\*)
│       │   Bootstrap, Components, Services, Migration, Util
│       └── Resources/                shared config.xml, views, snippets
│
├── admin-shared/                     ← plain JS / JSON, Vue-agnostic
│   ├── services/                     Shopware.Classes.ApiService subclasses
│   └── snippets/                     de-DE.json, en-GB.json, nl-NL.json, fr-FR.json
│
├── plugin-sw66/                      ← thin shell for Shopware 6.6
│   ├── composer.json.template
│   └── src/
│       ├── Mond1SW6.php              plugin entry (~30 lines)
│       ├── SW66/                     6.6-only classes
│       │   └── Document/Decorator/ZugferdCreditNoteRendererDecorator.php
│       └── Resources/
│           ├── config/services.xml   imports core + registers 6.6-only
│           └── app/administration/   Vue2 + webpack
│
├── plugin-sw67/                      ← thin shell for Shopware 6.7+
│   ├── composer.json.template
│   └── src/
│       ├── Mond1SW6.php              same technical name as 6.6
│       ├── SW67/                     6.7-only classes (if any)
│       └── Resources/
│           ├── config/services.xml   imports core + registers 6.7-only
│           └── app/administration/   Vue3 + Vite + TypeScript
│
├── build/
│   └── build.sh                      ./build/build.sh <version>
│
├── tests/                            shared PHPUnit + shell integration tests
├── ci/                               GitHub Actions matrix [6.6, 6.7]
└── dist/                             gitignored build output
    ├── mondu-payment-sw66-<v>.zip
    └── mondu-payment-sw67-<v>.zip
```

## Release flow

```
./build/build.sh 2.3.0
```

For each `plugin-swXX/`:

1. `rsync -a plugin-swXX/src/   dist/mondu-payment-swXX-2.3.0/src/`
2. `rsync -a core/src/          dist/mondu-payment-swXX-2.3.0/src/`   ← overlay
3. `rsync -a admin-shared/services/  dist/…/Resources/app/administration/src/services/`
4. `rsync -a admin-shared/snippets/   dist/…/Resources/app/administration/src/module/sw-order/snippet/`
5. `sed -i "s/__VERSION__/2.3.0/" dist/…/composer.json.template > dist/…/composer.json`
6. Run native bundler: webpack (6.6) or vite build (6.7).
7. `zip -r dist/mondu-payment-swXX-2.3.0.zip dist/mondu-payment-swXX-2.3.0/`.

Output: two self-contained ZIPs. Merchants get one plugin each — they never see
`core/` or the other plugin.

## Development flow

Work in `core/` for any backend change — it immediately affects both plugins on
next build. Work in `plugin-swXX/` only for things that are genuinely specific
to that Shopware major (admin-bundle wiring, version-specific decorators).

For live testing against a docker shop: `./build/build.sh 0.0.0-dev` and point
the shop at `dist/mondu-payment-swXX-0.0.0-dev/`.

## Namespace convention

Every PHP file — in `core/`, `plugin-sw66/` and `plugin-sw67/` — uses a single
namespace root `Mondu\MonduPayment\*`. This keeps Shopware's PSR-4 expectations
happy (Plugin class in `src/` under the plugin's namespace) without needing
cross-package composer sub-deps.

- `core/src/` → `Mondu\MonduPayment\*`  (everything shared)
- `plugin-sw66/src/` → `Mondu\MonduPayment\*`  (Plugin entry, SW66/ subnamespace)
- `plugin-sw67/src/` → `Mondu\MonduPayment\*`  (Plugin entry, SW67/ subnamespace)

During `build`, core/src is **overlaid** into the dist plugin's `src/`, keeping
the single PSR-4 prefix.

## Why "inline" and not a composer sub-package

Shopware Community Store packaging is unreliable around nested composer
dependencies; merchants debugging the plugin expect to see the code in
`custom/plugins/Mond1SW6/src/…` and not in `custom/plugins/Mond1SW6/vendor/…`.
Inlining the shared code at build time removes that entire class of problem.
