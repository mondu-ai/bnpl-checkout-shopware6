import MonduConfigService from '../services/mondu-config.service'

Shopware.Service().register('monduConfigService', () => {
  const initContainer = Shopware.Application.getContainer('init');
  return new MonduConfigService(
    initContainer.httpClient, Shopware.Service('loginService')
  );
});
