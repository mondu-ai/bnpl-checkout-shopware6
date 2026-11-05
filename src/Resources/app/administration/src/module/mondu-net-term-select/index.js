import template from './mondu-net-term-select.html.twig';

/**
 * Net term field of the plugin config. Offers the terms the merchant holds in Mondu for
 * the payment method instead of a fixed list, see NetTermOptionsController. A term that
 * is selected but not held (any more) stays in the list, marked, so it can be removed.
 * Without an answer from Mondu, e.g. before the API token is saved, the fixed list is used.
 */
const FALLBACK_NET_TERMS = [3, 7, 14, 15, 30, 45, 60, 90];

Shopware.Component.register('mondu-net-term-select', {
  template,

  inject: ['monduConfigService'],

  inheritAttrs: false,

  emits: ['update:value'],

  props: {
    value: {
      type: Array,
      required: false,
      default: () => [],
    },
    monduMethod: {
      type: String,
      required: true,
    },
  },

  data() {
    return {
      offeredNetTerms: null,
      isLoading: false,
    };
  },

  computed: {
    // Older saves hold numbers, the select works with strings.
    selected() {
      return [...new Set((this.value || []).map((days) => String(days)))];
    },

    options() {
      const offered = this.offeredNetTerms ?? FALLBACK_NET_TERMS;
      const all = [...new Set([...offered, ...this.selected.map(Number)])].sort((a, b) => a - b);

      const unit = this.$t('mondu-net-term-select.days');
      const notOffered = this.$t('mondu-net-term-select.notOffered');

      return all.map((days) => ({
        value: String(days),
        label: offered.includes(days) ? `${days} ${unit}` : `${days} ${unit} (${notOffered})`,
      }));
    },
  },

  created() {
    // The config page switches the sales channel without rendering the field anew.
    const systemConfig = this.findSystemConfig();
    if (systemConfig) {
      this.$watch(() => systemConfig.currentSalesChannelId, this.loadNetTerms, { immediate: true });
    } else {
      this.loadNetTerms(null);
    }
  },

  methods: {
    findSystemConfig() {
      let parent = this.$parent;
      while (parent) {
        if (parent.currentSalesChannelId !== undefined && parent.actualConfigData !== undefined) {
          return parent;
        }
        parent = parent.$parent;
      }

      return null;
    },

    loadNetTerms(salesChannelId) {
      this.isLoading = true;

      this.monduConfigService.getNetTermOptions(this.monduMethod, salesChannelId)
        .then((data) => {
          this.offeredNetTerms = Array.isArray(data.netTerms) ? data.netTerms : null;
        })
        .catch(() => {
          this.offeredNetTerms = null;
        })
        .finally(() => {
          this.isLoading = false;
        });
    },

    onUpdate(value) {
      this.$emit('update:value', value);
    },
  },
});
