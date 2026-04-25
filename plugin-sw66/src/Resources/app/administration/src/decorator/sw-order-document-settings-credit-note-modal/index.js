/**
 * Decorator to fix ZUGFeRD invoice filtering in Credit Note modal
 * 
 * Problem: Shopware's default Credit Note modal only shows documents with technicalName === 'invoice'
 * Solution: Override createdComponent to also include 'zugferd_embedded_invoice' documents
 * 
 * Original file: vendor/shopware/administration/.../sw-order-document-settings-credit-note-modal/index.js
 */

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
Shopware.Component.override('sw-order-document-settings-credit-note-modal', {
    methods: {
        createdComponent() {
            this.$super('createdComponent');

            // Override: Include both 'invoice' and 'zugferd_embedded_invoice' documents
            const invoiceNumbers = this.order.documents
                .filter((document) => {
                    return document.documentType.technicalName === 'invoice' ||
                           document.documentType.technicalName === 'zugferd_embedded_invoice';
                })
                .map((item) => {
                    return item.config.custom.invoiceNumber;
                });

            this.invoiceNumbers = [...new Set(invoiceNumbers)].sort();
        },
    },
});

