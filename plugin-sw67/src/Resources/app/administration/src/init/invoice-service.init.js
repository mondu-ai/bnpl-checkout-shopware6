import InvoiceService from '../services/invoice.service'

Shopware.Service().register('invoiceApiService', (container) => {
  const initContainer = Shopware.Application.getContainer('init');
  return new InvoiceService(
    initContainer.httpClient, Shopware.Service('loginService')
  );
});
