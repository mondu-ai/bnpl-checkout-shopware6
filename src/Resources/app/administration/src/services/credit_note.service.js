export default class CreditNoteService extends Shopware.Classes.ApiService {
  constructor(httpClient, loginService, apiEndpoint = 'mondus') {
    super(httpClient, loginService, apiEndpoint);
  }

  cancel(orderId, creditNoteId) {
    return this.httpClient
      .post(`/mondu/orders/${orderId}/credit_notes/${creditNoteId}/cancel`,
        {
          headers: this.getBasicHeaders()
        })
      .then(response => response.data)
  }
}