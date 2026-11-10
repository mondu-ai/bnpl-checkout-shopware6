import MonduAsyncOrderService from '../services/mondu-async-order.service';

Shopware.Service().register('monduAsyncOrderService', () => {
  const initContainer = Shopware.Application.getContainer('init');
  return new MonduAsyncOrderService(
    initContainer.httpClient, Shopware.Service('loginService')
  );
});
