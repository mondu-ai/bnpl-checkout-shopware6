import MonduConfigService from '../services/mondu-config.service'

Shopware.Service().register('monduConfigService', (container) => {
  const initContainer = Shopware.Application.getContainer('init');
  return new MonduConfigService(
    initContainer.httpClient, Shopware.Service('loginService')
  );
});
