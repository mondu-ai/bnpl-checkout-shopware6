import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class MonduCheckoutPlugin extends Plugin {
    static options = {
        src: '',
        csrfToken: '',
        payment_method: 'invoice'
    };

    init() {
        this._isWidgetLoaded = this._initWidget(this.options.src);
        this._registerEvents();
        this._registerProperties();
    }

    _registerEvents() {
        this.el.form.addEventListener('submit', this._submitForm.bind(this));
    }

    _registerProperties() {
        this._checkoutConfirmPage = this.el.getAttribute('data-checkout-confirm-page');
        this._monduTokenUrl = this.el.getAttribute('data-mondu-token-url');
    }

    async _initWidget(src) {
        return new Promise((resolve, reject) => {
            const monduSkd = document.createElement('script');
            monduSkd.src = src;
            document.head.appendChild(monduSkd);
            monduSkd.onload = function () {
                resolve(true);
            }
            monduSkd.onerror = function () {
                resolve(false);
            }
        })
    }

    async _submitForm(event) {
        if (this._isWidgetComplete()) {
            return true;
        }

        event.preventDefault();

        if (await this._isWidgetLoaded) {
            this._appendWidgetContainer();
            const { token } = await this._getMonduToken();
            this._appendTokenToInput(token);
            const removeWidgetContainer = this._removeWidgetContainer.bind(this);

            let that = this;

            window.monduCheckout.render({
                token,
                onClose() {
                    removeWidgetContainer();
                    if (that._isWidgetComplete()) {
                        that.el.form.submit();
                    } else {
                        window.location.href = that._checkoutConfirmPage;
                    }
                },
                onSuccess() {
                    that._setMonduComplete('1');
                }
            });
        }
    }

    async _getMonduToken() {
        return new Promise(resolve => {
            const client = new HttpClient(window.accessKey, window.contextToken);

            client.get(this._monduTokenUrl + `?payment_method=${this.options.payment_method}`, (response) => {
                try {
                    resolve(JSON.parse(response));
                } catch {
                    resolve(null);
                }
            });
        })
    }

    _appendWidgetContainer() {
        document.getElementById('mondu-checkout-widget').style.display = 'initial';
    }

    _removeWidgetContainer() {
        const widgetContainer = document.getElementById("mondu-checkout-widget");
        if (widgetContainer) {
            widgetContainer.style.display = 'none';
            // window.monduCheckout.destroy();
        }
    }

    _setMonduComplete(flag) {
        this.el.form.dataset.monduComplete = parseInt(flag, 10);
    }

    _isWidgetComplete() {
        return parseInt(this.el.form.dataset.monduComplete, 10) === 1;
    }

    _appendTokenToInput(token) {
        document.getElementById('mondu-order-id-input').value = token;
    }
}