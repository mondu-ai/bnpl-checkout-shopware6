import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class MonduCheckoutPlugin extends Plugin {
    static options = {
        src: '',
        csrfToken: ''
    };

    init() {
        this._isWidgetLoaded = this._initWidget(this.options.src);
        this._registerEvents();
    }

    _registerEvents() {
        this.el.form.addEventListener('submit', this._submitForm.bind(this))
    }

    async _initWidget(src) {
        return new Promise((resolve, reject) => {
            const monduSkd = document.createElement('script');
            monduSkd.src= src;
            document.head.appendChild(monduSkd);
            monduSkd.onload = function() {
                resolve(true);
            }
            monduSkd.onerror = function() {
                resolve(false);
            }
        })
    }

    async _submitForm(event) {
        if(this._isWidgetComplete()) {
            return true;
        }

        event.preventDefault();

        if(await this._isWidgetLoaded) {
            this._appendWidgetContainer();
            const { token } = await this._getMonduToken();
            this._appendTokenToInput(token);
            const removeWidgetContainer = this._removeWidgetContainer.bind(this);

            function submitForm() {
                this.el.form.requestSubmit();
            }

            function monduComplete() {
                this._setMonduComplete('1');
            }

            let that = this;

            window.monduCheckout.render({
                token,
                onClose() {
                    removeWidgetContainer();
                    if(that._isWidgetComplete() || true) {
                        //TODO bug workaround
                        monduComplete.apply(that);
                        submitForm.apply(that);
                    } else {
                        location.reload();
                    }
                },
                onSuccess() {
                    monduComplete.apply(that);
                }
            });
        }
    }

    async _getMonduToken() {
        return new Promise(resolve => {
            let url = "/mondu-payment/token";

            const client = new HttpClient(window.accessKey, window.contextToken);

            client.get(url, (response) => {
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
        if(widgetContainer) {
            widgetContainer.style.display = 'none';
            // window.monduCheckout.destroy();
        }
    }

    _setMonduComplete(flag) {
        this.el.form.dataset.monduComplete = parseInt(flag);
    }

    _isWidgetComplete() {
        return parseInt(this.el.form.dataset.monduComplete) === 1;
    }

    _appendTokenToInput(token) {
        document.getElementById('mondu-order-id-input').value = token;
    }
}