/**
 * Shared between the order-create page and the Mondu fields on it.
 *
 * The fields are saved to the admin's sales channel context as they change; the page waits
 * for the last save before it places the order, so the order never goes out with stale values.
 */
const asyncOrderState = {
  pendingSave: null,
  monduPaymentMethodIds: [],
};

export default asyncOrderState;
