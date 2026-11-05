import asyncOrderState from '../../async-order-state';

const { Component, Store } = Shopware;

/**
 * Shows the Mondu fields on the order-create page whenever the selected payment method is a
 * Mondu one that may be used for an order created in the admin.
 *
 * Reads the sales channel, payment method, billing address and cart from the order-create
 * store, so it shows the same on the General and the Details tab.
 */
Component.register('mondu-async-order-card', {
  template: `
    <mondu-async-order-fields
        v-if="showFields"
        :key="config.method + '|' + config.countryCode"
        :config="config"
        :context-token="contextToken"
        :customer="customer"
    />
  `,

  inject: ['monduAsyncOrderService'],

  data() {
    return {
      config: null,
    };
  },

  computed: {
    salesChannelContext() {
      return Store.get('swOrder').context;
    },

    customer() {
      return Store.get('swOrder').customer;
    },

    contextToken() {
      return Store.get('swOrder').cart?.token || '';
    },

    salesChannelId() {
      return this.salesChannelContext?.salesChannel?.id || this.customer?.salesChannelId || '';
    },

    paymentMethodId() {
      return this.salesChannelContext?.paymentMethod?.id || '';
    },

    billingAddressId() {
      return this.salesChannelContext?.customer?.activeBillingAddress?.id || '';
    },

    showFields() {
      return !!(this.config?.active && this.config?.method && this.config?.visibleFields && this.contextToken);
    },

    configParams() {
      return [this.salesChannelId, this.paymentMethodId, this.billingAddressId, this.contextToken].join('|');
    },
  },

  watch: {
    configParams: {
      immediate: true,
      handler() {
        this.loadConfig();
      },
    },
  },

  methods: {
    loadConfig() {
      if (!this.salesChannelId || !this.paymentMethodId) {
        this.config = null;
        return;
      }

      this.monduAsyncOrderService.getConfig({
        salesChannelId: this.salesChannelId,
        paymentMethodId: this.paymentMethodId,
        billingAddressId: this.billingAddressId,
        contextToken: this.contextToken,
      }).then((config) => {
        asyncOrderState.monduPaymentMethodIds = config.monduPaymentMethodIds || [];
        this.config = config;
      }).catch(() => {
        this.config = null;
      });
    },
  },
});
