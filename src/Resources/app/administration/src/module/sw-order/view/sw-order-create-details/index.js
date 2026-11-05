import template from './sw-order-create-details.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Order-create Details tab: offers Mondu only when admin orders are switched on and available
 * for the account (pay now never), and shows the Mondu fields under the payment method.
 */
Component.override('sw-order-create-details', {
  template,

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
