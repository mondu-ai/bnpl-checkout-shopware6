import template from './sw-order-document-card.html.twig';

Shopware.Component.override('sw-order-document-card', {
  template,
  inject: ['invoiceApiService', 'creditNoteApiService'],
  mixins: [
    'notification'
  ],
  methods: {
    onCancelInvoice(invoiceId, orderId) {
      this.invoiceApiService.cancel(orderId, invoiceId).then((response) => {
        if (response.status === 'already_cancelled') {
          this.createNotificationInfo({
            title: this.$tc('sw-order-mondu.documentCard.cancelAlreadyCancelledTitle'),
            message: this.$tc('sw-order-mondu.documentCard.cancelAlreadyCancelledMessage')
          });
        } else {
          this.createNotificationSuccess({
            title: this.$tc('sw-order-mondu.documentCard.cancelSuccessTitle'),
            message: this.$tc('sw-order-mondu.documentCard.cancelSuccessMessage')
          });
        }
      }).catch((error) => {
        if (error['error'] != '0') {
          this.createNotificationError({
            message: this.$tc('sw-order-mondu.documentCard.cancelErrorMessage')
          });
        }
      });
    },
    onCancelCreditNote(creditNoteId, orderId) {
      this.creditNoteApiService.cancel(orderId, creditNoteId).then((response) => {
        this.createNotificationSuccess({
          title: this.$tc('sw-order-mondu.documentCard.cancelSuccessTitle'),
          message: this.$tc('sw-order-mondu.documentCard.cancelSuccessMessage')
        });
      }).catch((error) => {
        const payload = error?.response?.data ?? error ?? {};
        const status = payload.status || '';

        const snippetKey = {
          already_cancelled: 'sw-order-mondu.documentCard.cancelErrorAlreadyCancelled',
          not_found_in_mondu: 'sw-order-mondu.documentCard.cancelErrorNotFound',
          credit_note_not_registered_in_mondu: 'sw-order-mondu.documentCard.cancelErrorNotRegistered',
          invoice_not_registered_in_mondu: 'sw-order-mondu.documentCard.cancelErrorNotRegistered',
          document_not_found: 'sw-order-mondu.documentCard.cancelErrorNotRegistered',
          invoice_number_missing: 'sw-order-mondu.documentCard.cancelErrorNotRegistered'
        }[status] || 'sw-order-mondu.documentCard.cancelErrorMessage';

        this.createNotificationError({
          message: this.$tc(snippetKey)
        });
      });
    }
  }
});
