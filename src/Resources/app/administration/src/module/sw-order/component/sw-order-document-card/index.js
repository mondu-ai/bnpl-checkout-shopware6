import template from './sw-order-document-card.html.twig';

Shopware.Component.override('sw-order-document-card', {
  template,
  inject: ['invoiceApiService', 'creditNoteApiService'],
  mixins: [
    'notification'
  ],
  data() {
    return {
      monduDocumentStatuses: {}
    };
  },
  computed: {
    isMonduOrder() {
      if (!this.order?.transactions) return false;
      return this.order.transactions.some(
        t => t.paymentMethod?.handlerIdentifier?.includes('Mondu')
      );
    },

    cancelledDocumentIds() {
      const ids = new Set();
      if (!this.documents) return ids;
      const cancellationTypes = [
        'storno',
        'cancellation_invoice',
        'zugferd_cancellation_invoice',
        'zugferd_embedded_cancellation_invoice'
      ];
      this.documents.forEach(doc => {
        if (cancellationTypes.includes(doc.documentType?.technicalName) && doc.referencedDocumentId) {
          ids.add(doc.referencedDocumentId);
        }
      });
      return ids;
    },

    getDocumentColumns() {
      const columns = this.$super('getDocumentColumns');
      if (!this.isMonduOrder) return columns;

      const sentIndex = columns.findIndex(col => col.property === 'sent');
      const insertAt = sentIndex !== -1 ? sentIndex : columns.length;

      const extraColumns = [
        {
          property: 'shopwareStatus',
          label: 'sw-order-mondu.documentCard.columnShopwareStatus',
          allowResize: false,
          sortable: false,
          align: 'center'
        },
        {
          property: 'monduStatus',
          label: 'sw-order-mondu.documentCard.columnMonduStatus',
          allowResize: false,
          sortable: false,
          align: 'center'
        }
      ];

      return [
        ...columns.slice(0, insertAt),
        ...extraColumns,
        ...columns.slice(insertAt)
      ];
    }
  },
  watch: {
    'order.id': {
      immediate: true,
      handler(orderId) {
        if (orderId && this.isMonduOrder) {
          this.loadMonduStatuses(orderId);
        }
      }
    }
  },
  methods: {
    loadMonduStatuses(orderId) {
      this.invoiceApiService.getDocumentStatuses(orderId).then((statuses) => {
        this.monduDocumentStatuses = statuses;
      }).catch(() => {
        this.monduDocumentStatuses = {};
      });
    },

    getMonduStatus(documentId) {
      return this.monduDocumentStatuses[documentId] || 'not_sent';
    },

    getMonduStatusLabel(documentId) {
      const status = this.getMonduStatus(documentId);
      const key = {
        not_sent: 'sw-order-mondu.documentCard.monduStatusNotSent',
        sent: 'sw-order-mondu.documentCard.monduStatusSent',
        cancelled: 'sw-order-mondu.documentCard.monduStatusCancelled'
      }[status];
      return key ? this.$tc(key) : status;
    },

    getMonduStatusVariant(documentId) {
      const status = this.getMonduStatus(documentId);
      return { not_sent: 'info', sent: 'success', cancelled: 'danger' }[status] || 'neutral';
    },

    isCancellationType(technicalName) {
      return [
        'storno',
        'cancellation_invoice',
        'zugferd_cancellation_invoice',
        'zugferd_embedded_cancellation_invoice'
      ].includes(technicalName);
    },

    isCreditNoteType(technicalName) {
      return [
        'credit_note',
        'zugferd_credit_note',
        'zugferd_embedded_credit_note'
      ].includes(technicalName);
    },

    getShopwareStatus(item) {
      const type = item.documentType?.technicalName;
      if (this.isCancellationType(type)) return 'storno';
      if (this.cancelledDocumentIds.has(item.id)) return 'cancelled';
      if (this.isCreditNoteType(type) && !item.referencedDocumentId) return 'cancelled';
      return 'active';
    },

    getShopwareStatusLabel(item) {
      const key = {
        storno: 'sw-order-mondu.documentCard.shopwareStatusStorno',
        cancelled: 'sw-order-mondu.documentCard.shopwareStatusCancelled',
        active: 'sw-order-mondu.documentCard.shopwareStatusActive'
      }[this.getShopwareStatus(item)];
      return this.$tc(key);
    },

    getShopwareStatusVariant(item) {
      return { storno: 'warning', cancelled: 'danger', active: 'success' }[this.getShopwareStatus(item)];
    },

    getList() {
      return this.$super('getList').then(() => {
        if (this.order?.id && this.isMonduOrder) {
          this.loadMonduStatuses(this.order.id);
        }
      });
    },

    onCreateDocument(params, additionalAction, referencedDocumentId) {
      return this.$super('onCreateDocument', params, additionalAction, referencedDocumentId)
        ?.catch?.((error) => {
          const errorDetail = error?.response?.data?.errors?.[0]?.detail || error?.message || '';
          const isCreditNote = params?.type === 'credit_note'
            || params?.type === 'zugferd_credit_note'
            || params?.type === 'zugferd_embedded_credit_note';

          if (isCreditNote && errorDetail.includes('violation')) {
            this.createNotificationError({
              title: this.$tc('sw-order-mondu.documentCard.creditNoteViolationTitle'),
              message: this.$tc('sw-order-mondu.documentCard.creditNoteViolationMessage')
            });
            return;
          }

          throw error;
        });
    },

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
        this.loadMonduStatuses(orderId);
      }).catch(() => {
        this.createNotificationError({
          message: this.$tc('sw-order-mondu.documentCard.cancelErrorMessage')
        });
      });
    },

    onCancelCreditNote(creditNoteId, orderId) {
      this.creditNoteApiService.cancel(orderId, creditNoteId).then((response) => {
        this.createNotificationSuccess({
          title: this.$tc('sw-order-mondu.documentCard.cancelSuccessTitle'),
          message: this.$tc('sw-order-mondu.documentCard.cancelSuccessMessage')
        });
        this.loadMonduStatuses(orderId);
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
