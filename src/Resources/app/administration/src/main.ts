import './module/sw-order';
import './init/invoice-service.init';
import './init/credit_note-service.init';
import './init/mondu-config-service.init';
import './module/sw-mondu-test-api-button';

// Import decorator to fix ZUGFeRD invoice filtering in Credit Note modal
import './decorator/sw-order-document-settings-credit-note-modal';

// Import decorator to show Mondu-specific errors on state machine transitions
import './decorator/sw-order-state-cards';

// Register snippets for all supported locales
import snEn from './module/sw-order/snippet/en-GB.json';
import snDe from './module/sw-order/snippet/de-DE.json';
import snNl from './module/sw-order/snippet/nl-NL.json';
import snFr from './module/sw-order/snippet/fr-FR.json';

Shopware.Locale.extend('en-GB', snEn);
Shopware.Locale.extend('de-DE', snDe);
Shopware.Locale.extend('nl-NL', snNl);
Shopware.Locale.extend('fr-FR', snFr);

