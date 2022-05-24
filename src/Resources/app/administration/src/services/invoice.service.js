export default class InvoiceService extends Shopware.Classes.ApiService {
  constructor(httpClient, loginService, apiEndpoint = 'mondus') {
    super(httpClient, loginService, apiEndpoint);
  }

  cancel(orderId, invoiceId) {
    return this.httpClient
      .post(`/mondu/orders/${orderId}/${invoiceId}/cancel`,
        {
          headers: this.getBasicHeaders()
        })
      .then(response => response.data)
  }
}