import './component';
import './view';

// Import locales/snippets for sw-order module
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import nlNL from './snippet/nl-NL.json';
import frFR from './snippet/fr-FR.json';

// Register snippets
Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('nl-NL', nlNL);
Shopware.Locale.extend('fr-FR', frFR);
