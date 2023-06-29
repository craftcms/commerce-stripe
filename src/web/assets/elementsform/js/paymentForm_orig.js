function PaymentIntentsElements(publishableKey, container) {
    this.container = container;
    this.formNamespace = this.container.dataset.paymentFormNamespace;
    this.stripeInstance = Stripe(publishableKey);
    this.elements = null;
    this.completeActionUrl = this.container.dataset.completePaymentActionUrl;
    const self = this;

    function getFormData() {
        return new FormData(self.container.closest('form'));
    }

    function showErrorMessage(message) {
        self.container.classList.remove('hidden');
        self.container.querySelector('.stripe-error-message').classList.remove('hidden');
        self.container.querySelector('.stripe-error-message').innerText = message;
    }

    this.handle = function () {

        if (getFormData().get('action').includes('commerce/payments/pay')) {
            this.cartPaymentFlow();
        }

        if (getFormData().get('action').includes('commerce/payment-sources/add')) {
            this.setupIntentFlow();
        }

        if (getFormData().get('action').includes('commerce/subscriptions/create')) {
            this.setupIntentFlow();
        }
    }

    function createStripeElementsForm(options) {

        self.elements = self.stripeInstance.elements(options);

        const paymentElement = self.elements.create('payment',
            JSON.parse(self.container.dataset.elementOptions)
        );
        const paymentElementDiv = self.container.querySelector('.stripe-payment-element');
        paymentElement.mount(paymentElementDiv);

        self.container.classList.remove('hidden');
    }

    this.setupIntentFlow = function () {
        let form = getFormData.call(this);
        let setupIntentFormData = new FormData();
        setupIntentFormData.append('action', 'commerce-stripe/customers/create-setup-intent');
        setupIntentFormData.append(window.csrfTokenName, window.csrfTokenValue);
        setupIntentFormData.append('gatewayId', this.container.dataset.gatewayId);
        let responseError = false;

        fetch(window.location.href, {
            method: 'post',
            body: setupIntentFormData,
            headers: {
                Accept: 'application/json',
            },
        }).then(function (res) {
            if (res.status !== 200) {
                responseError = true;
            }
            return res.json();
        }).then(function (json) {

            if (responseError) {
                showErrorMessage(json.error);

                return;
            }

            const options = {
                clientSecret: json.client_secret,
                appearance: JSON.parse(self.container.dataset.appearance)
            };

            createStripeElementsForm(options);

            self.container.querySelector('.stripe-payment-elements-submit-button').addEventListener('click', async (event) => {

                event.preventDefault();
                self.container.classList.add('hidden');
                let elements = self.elements;

                let form = getFormData.call(this);
                const formDataArray = [...form.entries()];
                const params = formDataArray
                    .map(x => `${encodeURIComponent(x[0])}=${encodeURIComponent(x[1])}`)
                    .join('&');

                self.stripeInstance.confirmSetup({
                    elements,
                    confirmParams: {
                        return_url: container.dataset.confirmSetupIntentUrl+'&'+ params,
                    },
                }).then(function(result) {
                    if (result.error) {
                        showErrorMessage(result.error.message);
                    }
                });
            });
        });
    };

    this.cartPaymentFlow = function () {
        const form = getFormData.call(this);
        let responseError = false;

        // We immediately attempt payment intent creation, so we can get the client secret and payment intent ID
        fetch(window.location.href, {
            method: 'post',
            body: form,
            headers: {
                Accept: 'application/json',
            },
        }).then(function (res) {
            if (res.status !== 200) {
                responseError = true;
            }
            return res.json();
        }).then(function (json) {
            if (responseError) {
                self.container.classList.add('hidden');
                showErrorMessage(json.message);
                return;
            }

            let completeActionUrl = new URL(self.completeActionUrl);
            completeActionUrl.searchParams.append('commerceTransactionHash', json.transactionHash);
            completeActionUrl.searchParams.append('commerceTransactionId', json.transactionId);
            self.completeActionUrl = completeActionUrl.toString();

            const options = {
                clientSecret: json.redirectData.client_secret,
                appearance: JSON.parse(self.container.dataset.appearance)
            };

            createStripeElementsForm(options);
        });

        self.container.querySelector('.stripe-payment-elements-submit-button').addEventListener('click', async (event) => {
            event.preventDefault();
            self.container.classList.add('hidden');
            let elements = self.elements;
            const {error} = await self.stripeInstance.confirmPayment({
                elements,
                confirmParams: {
                    return_url: self.completeActionUrl,
                },
            });

            showErrorMessage(error.message);
        });

    }
}

function initStripe() {
    if (typeof Stripe === 'undefined') {
        setTimeout(initStripe, 200);
    } else {
        document.querySelectorAll('.stripe-payment-elements-form').forEach(function (container) {
            let handlerInstance = new PaymentIntentsElements(
                container.dataset.publishablekey,
                container
            );
            container.dataset.handlerInstance = handlerInstance;
            handlerInstance.handle();
        });
    }
}

initStripe();
