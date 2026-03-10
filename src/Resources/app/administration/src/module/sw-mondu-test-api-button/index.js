import template from './sw-mondu-test-api-button.html.twig';

Shopware.Component.register('mondu-test-api-button', {
  template: template,
  inject: ['monduConfigService'],
  mixins: [
    'notification'
  ],
  methods: {
    getConfigValues() {
      // Traverse $parent to find sw-system-config and read actualConfigData
      let parent = this.$parent;
      while (parent) {
        if (parent.actualConfigData !== undefined && parent.currentSalesChannelId !== undefined) {
          const salesChannelId = parent.currentSalesChannelId;
          const config = parent.actualConfigData[salesChannelId]
            || parent.actualConfigData['null']
            || {};
          return {
            apiCredentials: config['Mond1SW6.config.apiToken'] || '',
            sandboxMode: !!config['Mond1SW6.config.sandbox'],
          };
        }
        parent = parent.$parent;
      }
      // Fallback: DOM selectors (SW 6.6 style)
      const tokenEl = document.getElementById('Mond1SW6.config.apiToken');
      const sandboxEl = document.querySelector('[name="Mond1SW6.config.sandbox"]');
      return {
        apiCredentials: tokenEl ? tokenEl.value : '',
        sandboxMode: sandboxEl ? sandboxEl.checked : false,
      };
    },

    onTestApi() {
      const { apiCredentials, sandboxMode } = this.getConfigValues();

      this.createNotificationInfo({
        title: this.$tc('sw-mondu-config.apiValidation.apiConfigurationValidationTitle'),
        message: this.$tc('sw-mondu-config.apiValidation.apiConfigurationValidationMessage')
      });

      this.monduConfigService.testApiCredentials(apiCredentials, sandboxMode).then(() => {
        this.createNotificationSuccess({
          title: this.$tc('sw-mondu-config.apiValidation.apiConfigurationSuccessTitle'),
          message: this.$tc('sw-mondu-config.apiValidation.apiConfigurationSuccessMessage')
        });
      }).catch(() => {
        this.createNotificationError({
          title: this.$tc('sw-mondu-config.apiValidation.apiConfigurationFailureTitle'),
          message: this.$tc('sw-mondu-config.apiValidation.apiConfigurationFailureMessage')
        });
      });
    }
  }
});