import asyncOrderState from '../../async-order-state';

const { Component } = Shopware;

/**
 * Order-create page: places the order only once the Mondu fields are saved, and skips the
 * "remind the customer to pay" step for Mondu orders. Mondu asks the buyer to confirm the
 * order by email itself; the reminder would send the buyer to pay it again in the shop.
 */
Component.override('sw-order-create', {
  methods: {
    async onSaveOrder() {
      if (asyncOrderState.flush) {
        await asyncOrderState.flush();
      } else if (asyncOrderState.pendingSave) {
        await asyncOrderState.pendingSave;
      }

      await this.$super('onSaveOrder');

      const paymentMethodId = this.orderTransaction?.paymentMethodId;
      if (this.showRemindPaymentModal && asyncOrderState.monduPaymentMethodIds.includes(paymentMethodId)) {
        this.onRemindPaymentModalClose();
      }
    },
  },
});
