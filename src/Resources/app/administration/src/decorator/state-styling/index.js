/**
 * Colours the payment states an order created in the admin waits in (Mondu async orders).
 */
Shopware.Application.addServiceProviderDecorator('stateStyleDataProviderService', (stateStyleService) => {
  stateStyleService.addStyle('order_transaction.state', 'mondu_payment_review', {
    icon: 'progress',
    color: 'progress',
    variant: 'progress',
  });

  stateStyleService.addStyle('order_transaction.state', 'mondu_pending_buyer_confirmation', {
    icon: 'progress',
    color: 'warning',
    variant: 'warning',
  });

  return stateStyleService;
});
