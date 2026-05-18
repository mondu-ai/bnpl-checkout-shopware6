import template from './sw-order-detail-general.html.twig';

const { Component } = Shopware;

Component.override('sw-order-detail-general', {
    template,
    inject: ['invoiceApiService'],
    data() {
        return {
            monduAmountCents: null,
            monduCurrency: null,
            monduAmountLoading: false,
        };
    },
    computed: {
        isMonduOrder() {
            if (!this.order?.transactions) return false;
            return this.order.transactions.some(
                t => t.paymentMethod?.handlerIdentifier?.includes('Mondu')
            );
        },
        monduAmountDiffers() {
            if (this.monduAmountCents === null) return false;
            const shopwareAmountCents = Math.round(this.order.price.totalPrice * 100);
            return shopwareAmountCents !== this.monduAmountCents;
        },
        formattedMonduAmount() {
            if (this.monduAmountCents === null) return '';
            const amount = this.monduAmountCents / 100;
            const currencyCode = this.monduCurrency || this.order?.currency?.isoCode || 'EUR';
            const decimals = this.order?.totalRounding?.decimals ?? 2;
            return Shopware.Filter.getByName('currency')(amount, currencyCode, decimals);
        },
    },
    watch: {
        'order.id': {
            immediate: true,
            handler(orderId) {
                if (orderId && this.isMonduOrder) {
                    this.loadMonduAmount(orderId);
                }
            }
        }
    },
    methods: {
        loadMonduAmount(orderId) {
            this.monduAmountLoading = true;
            this.invoiceApiService.getMonduAmount(orderId).then((data) => {
                this.monduAmountCents = data.gross_amount_cents;
                this.monduCurrency = data.currency;
            }).catch(() => {
                this.monduAmountCents = null;
            }).finally(() => {
                this.monduAmountLoading = false;
            });
        }
    }
});
