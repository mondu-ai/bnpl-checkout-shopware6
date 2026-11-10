/**
 * Backs the Mondu fields on the order-create page (orders created through POST /orders/create_async).
 */
export default class MonduAsyncOrderService extends Shopware.Classes.ApiService {
  constructor(httpClient, loginService, apiEndpoint = 'mondu') {
    super(httpClient, loginService, apiEndpoint);
  }

  getConfig({ salesChannelId, paymentMethodId, billingAddressId, contextToken }) {
    return this.httpClient
      .get('/mondu/async-order/config', {
        params: { salesChannelId, paymentMethodId, billingAddressId, contextToken },
        headers: this.getBasicHeaders(),
      })
      .then(response => response.data);
  }

  saveFields(contextToken, fields) {
    return this.httpClient
      .post('/mondu/async-order/fields', { contextToken, fields }, { headers: this.getBasicHeaders() })
      .then(response => response.data);
  }
}
