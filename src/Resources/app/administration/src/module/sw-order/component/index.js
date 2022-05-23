import './sw-order-document-card';

const { Component } = Shopware;

Component.override('sw-order-state-history-card', {
  methods: {
    createStateChangeErrorNotification(error) {
      const { errors } = JSON.parse(error.response.request.response);
      const transitionError = errors.pop();

      if (error.response && error.response.data && error.response.data.errors && transitionError) {
        if (transitionError.code === 'MONDU__ERROR') {
          this.createNotificationError({
            message: transitionError.detail
          });
        } else {
          this.$super('createStateChangeErrorNotification', error)
        }
      } else {
        this.$super('createStateChangeErrorNotification', error)
      }
    }
  }
});