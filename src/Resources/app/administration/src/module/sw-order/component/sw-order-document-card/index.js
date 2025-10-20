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
        this.createNotificationSuccess({
          title: this.$tc('sw-order-mondu.documentCard.cancelSuccessTitle'),
          message: this.$tc('sw-order-mondu.documentCard.cancelSuccessMessage')
        });
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
        if (error['error'] != '0') {
          this.createNotificationError({
            message: this.$tc('sw-order-mondu.documentCard.cancelErrorMessage')
          });
        }
      });
    }
  }
});
