import './module/sw-order';
import './init/invoice-service.init';
import './init/credit_note-service.init';
import './init/mondu-config-service.init';
import './module/sw-mondu-test-api-button';

// Import locales/snippets
import deDE from '../snippet/de-DE.json';
import enGB from '../snippet/en-GB.json';

const { Application } = Shopware;

Application.addInitializerDecorator('locale', (localeFactory) => {
    localeFactory.extend('de-DE', deDE);
    localeFactory.extend('en-GB', enGB);
    return localeFactory;
});
