const { Component } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Initial order-create modal: hides the Mondu methods that cannot be used for an order
 * created in the admin, the same way the details page does.
 */
Component.override('sw-order-create-options', {
  inject: ['monduAsyncOrderService'],

  data() {
    return {
      monduHiddenPaymentMethodIds: [],
    };
  },

  computed: {
    paymentMethodCriteria() {
      const criteria = this.$super('paymentMethodCriteria');

      if (this.monduHiddenPaymentMethodIds.length) {
        criteria.addFilter(Criteria.not('AND', [Criteria.equalsAny('id', this.monduHiddenPaymentMethodIds)]));
      }

      return criteria;
    },
  },

  watch: {
    salesChannelId: {
      immediate: true,
      handler(salesChannelId) {
        if (!salesChannelId) {
          return;
        }

        this.monduAsyncOrderService.getConfig({ salesChannelId }).then((config) => {
          this.monduHiddenPaymentMethodIds = config.hiddenPaymentMethodIds || [];
        }).catch(() => {});
      },
    },
  },
});
