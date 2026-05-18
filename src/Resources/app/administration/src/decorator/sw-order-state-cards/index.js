/**
 * Decorator to show Mondu-specific error messages when state machine transitions fail.
 *
 * Overrides createStateChangeErrorNotification in both state card components
 * to extract and display the human-readable Mondu error detail (MONDU__INVOICE__ERROR).
 */

const monduStateCardOverride = {
    methods: {
        createStateChangeErrorNotification(error) {
            const errors = error?.response?.data?.errors ?? [];
            const monduError = errors.find((e) => e.code === 'MONDU__INVOICE__ERROR');

            if (monduError?.detail) {
                // Show only the Mondu-specific message, skip the generic SW error
                this.createNotificationError({
                    title: 'Mondu',
                    message: monduError.detail,
                    autoClose: false,
                });
                return;
            }

            this.$super('createStateChangeErrorNotification', error);
        },
    },
};

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
Shopware.Component.override('sw-order-details-state-card', monduStateCardOverride);
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
Shopware.Component.override('sw-order-state-history-card', monduStateCardOverride);
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
Shopware.Component.override('sw-order-general-info', monduStateCardOverride);
