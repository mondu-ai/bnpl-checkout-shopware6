/**
 * Shared between the order-create page and the Mondu fields on it.
 *
 * The fields are saved to the admin's sales channel context as they change. Before the page
 * places the order it saves them once more and waits for that, so the order never goes out
 * with stale or missing values, however quickly the merchant clicks save.
 */
const asyncOrderState = {
  pendingSave: null,
  flush: null,
  monduPaymentMethodIds: [],
  // What the merchant entered for the order being created (keyed by its context token), so a
  // re-created form, e.g. after switching the payment method, starts from the latest values.
  contextToken: null,
  values: {},
};

export default asyncOrderState;
