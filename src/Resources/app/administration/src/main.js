import './module/sw-order';
import './init/invoice-service.init';
import './init/credit_note-service.init';
import './init/mondu-config-service.init';
import './module/sw-mondu-test-api-button';

// Import decorator to fix ZUGFeRD invoice filtering in Credit Note modal
import './decorator/sw-order-document-settings-credit-note-modal';

// Import decorator to show Mondu-specific error messages in admin state cards
import './decorator/sw-order-state-cards';

// Import decorator to hide raw technical event id in Flow Builder list (6.6 shows it by default; 6.7 doesn't)
import './decorator/sw-flow-list';

// Import locales/snippets
import deDE from '../snippet/de-DE.json';
import enGB from '../snippet/en-GB.json';

// Register snippets for Shopware 6.6
Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
