import template from './sw-order-create-general.html.twig';

const { Component } = Shopware;

/**
 * Order-create General tab: shows the Mondu fields there too, since it is the tab the
 * order is usually saved from.
 */
Component.override('sw-order-create-general', {
  template,
});
