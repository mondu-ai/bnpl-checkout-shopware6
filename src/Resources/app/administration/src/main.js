import './module/sw-order';
import './init/invoice-service.init';
import './init/credit_note-service.init';
import './init/mondu-config-service.init';
import './module/sw-mondu-test-api-button';

// Import decorator to fix ZUGFeRD invoice filtering in Credit Note modal
import './decorator/sw-order-document-settings-credit-note-modal';

// Import locales/snippets
import deDE from '../snippet/de-DE.json';
import enGB from '../snippet/en-GB.json';

// Register snippets for Shopware 6.6
Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
