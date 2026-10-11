import template from './mondu-async-order-fields.html.twig';
import asyncOrderState from '../../async-order-state';

const { Component } = Shopware;

/**
 * Fields Mondu needs for an order created in the admin, shown under the payment method.
 *
 * Which fields show, which are required and which net terms are offered comes from
 * /api/mondu/async-order/config for the selected payment method and billing address.
 * Values are kept in the admin's sales channel context through /api/mondu/async-order/fields
 * and read back when the order is placed.
 */
Component.register('mondu-async-order-fields', {
  template,

  inject: ['monduAsyncOrderService'],

  props: {
    config: {
      type: Object,
      required: true,
    },
    contextToken: {
      type: String,
      required: true,
    },
    customer: {
      type: Object,
      required: false,
      default: null,
    },
  },

  data() {
    return {
      values: {},
      saveTimer: null,
    };
  },

  computed: {
    visibleFields() {
      const fields = [...(this.config.visibleFields || [])];

      if (this.isSoleTrader) {
        fields.push(...(this.config.ownerFields || []));
      }

      return fields;
    },

    requiredFields() {
      const fields = [...(this.config.requiredFields || [])];

      if (this.config.registrationIdRequired) {
        fields.push('registration_id');
      }
      if (this.isSoleTrader) {
        fields.push(...(this.config.ownerFields || []));
      }

      return fields;
    },

    isSoleTrader() {
      return this.values.legal_form_category === this.config.soleTraderCategory;
    },

    netTermOptions() {
      return (this.config.netTerms || []).map(days => ({
        value: String(days),
        label: this.$t('sw-order-mondu.asyncOrder.netTermDays', { days }),
      }));
    },

    installmentOptions() {
      return (this.config.installmentOptions || []).map(count => ({
        value: String(count),
        label: String(count),
      }));
    },

    legalFormCategoryOptions() {
      return (this.config.legalFormCategories || []).map(category => ({
        value: category,
        label: this.$t(`sw-order-mondu.asyncOrder.legalFormCategory.${category}`),
      }));
    },

    showNoNetTerms() {
      return this.isVisible('net_term') && this.netTermOptions.length === 0;
    },
  },

  watch: {
    config: {
      immediate: true,
      handler() {
        this.applyDefaults();
      },
    },
  },

  beforeUnmount() {
    this.flush();
  },

  methods: {
    isVisible(field) {
      return this.visibleFields.includes(field);
    },

    isRequired(field) {
      return this.requiredFields.includes(field);
    },

    /**
     * Starts from what is stored for this order, then fills what the merchant has not
     * chosen yet: the preselected net term, and the owner's name from the customer once
     * the buyer is a sole trader.
     */
    applyDefaults() {
      const values = { ...(this.config.values || {}), ...this.values };

      const netTerms = (this.config.netTerms || []).map(String);
      if (this.isVisible('net_term') && !netTerms.includes(values.net_term) && this.config.defaultNetTerm) {
        values.net_term = String(this.config.defaultNetTerm);
      }

      this.values = values;
      this.fillOwnerName();
      this.scheduleSave(0);
    },

    fillOwnerName() {
      if (!this.isSoleTrader || !this.customer) {
        return;
      }

      this.values = {
        ...this.values,
        owner_first_name: this.values.owner_first_name || this.customer.firstName,
        owner_last_name: this.values.owner_last_name || this.customer.lastName,
      };
    },

    onChange(field, value) {
      this.values = { ...this.values, [field]: value ?? '' };

      if (field === 'legal_form_category') {
        this.fillOwnerName();
      }

      this.scheduleSave(300);
    },

    scheduleSave(delay) {
      clearTimeout(this.saveTimer);
      this.saveTimer = setTimeout(() => this.flush(), delay);
    },

    flush() {
      clearTimeout(this.saveTimer);

      const fields = {};
      this.visibleFields.forEach((field) => {
        if (this.values[field] !== undefined && this.values[field] !== null) {
          fields[field] = String(this.values[field]);
        }
      });

      asyncOrderState.pendingSave = this.monduAsyncOrderService
        .saveFields(this.contextToken, fields)
        .catch(() => null);

      return asyncOrderState.pendingSave;
    },

    onBirthDateChange(value) {
      this.onChange('owner_birth_date', this.toDateString(value));
    },

    /**
     * Mondu wants YYYY-MM-DD. The datepicker may hand back a full ISO timestamp, whose UTC
     * date can be the day before the picked one, so the local calendar date is taken.
     */
    toDateString(value) {
      if (!value) {
        return '';
      }
      if (/^\d{4}-\d{2}-\d{2}$/.test(String(value))) {
        return String(value);
      }

      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return '';
      }

      const pad = number => String(number).padStart(2, '0');

      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    },
  },
});
