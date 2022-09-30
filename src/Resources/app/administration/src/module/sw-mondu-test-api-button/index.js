import template from './sw-mondu-test-api-button.html.twig';

Shopware.Component.register('mondu-test-api-button', {
  template: template,
  inject: ['monduConfigService'],
  mixins: [
    'notification'
  ],
  methods: {
    onTestApi() {
      this.createNotificationInfo({
        title: this.$tc('sw-mondu-config.apiValidation.apiConfigurationValidationTitle'),
        message: this.$tc('sw-mondu-config.apiValidation.apiConfigurationValidationMessage')
      });

      var apiCredentials = document.getElementById('Mond1SW6.config.apiToken').value;

      this.monduConfigService.testApiCredentials(apiCredentials).then((response) => {
        this.createNotificationSuccess({
          title: this.$tc('sw-mondu-config.apiValidation.apiConfigurationSuccessTitle'),
          message: this.$tc('sw-mondu-config.apiValidation.apiConfigurationSuccessMessage')
        });
      }).catch((error) => {
        if (error['error'] != '0') {
          this.createNotificationError({
            title: this.$tc('sw-mondu-config.apiValidation.apiConfigurationFailureTitle'),
            message: this.$tc('sw-mondu-config.apiValidation.apiConfigurationFailureMessage')
          });
        }
      });
    }
  }
});