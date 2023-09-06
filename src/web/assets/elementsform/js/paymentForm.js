class PaymentIntentsElements {
    constructor(publishableKey, container) {
        this.container = container;
        this.formNamespace = this.container.dataset.paymentFormNamespace;
        this.stripeInstance = Stripe(publishableKey);
        this.elements = null;
        this.completeActionUrl = this.container.dataset.completePaymentActionUrl;
    }

    getFormData() {
        return new FormData(this.container.closest('form'));
    }

    showErrorMessage(message) {
        this.container.classList.remove('hidden');
        const errorMessage = this.container.querySelector('.stripe-error-message');
        errorMessage.classList.remove('hidden');
        errorMessage.innerText = message;
    }

    createStripeElementsForm(options) {
        this.elements = this.stripeInstance.elements(options);
        const paymentElement = this.elements.create('payment', JSON.parse(this.container.dataset.elementOptions));
        const paymentElementDiv = this.container.querySelector('.stripe-payment-element');
        paymentElement.mount(paymentElementDiv);
        this.container.classList.remove('hidden');
    }

    setupIntentFlow() {
        const form = this.getFormData();
        const setupIntentFormData = new FormData();
        setupIntentFormData.append('action', 'commerce-stripe/customers/create-setup-intent');
        setupIntentFormData.append(window.csrfTokenName, window.csrfTokenValue);
        setupIntentFormData.append('gatewayId', this.container.dataset.gatewayId);
        let responseError = false;

        fetch(window.location.href, {
            method: 'post', body: setupIntentFormData, headers: {
                Accept: 'application/json',
            },
        })
            .then((res) => {
                if (res.status !== 200) {
                    responseError = true;
                }
                return res.json();
            })
            .then((json) => {
                if (responseError) {
                    this.showErrorMessage(json.error);
                    return;
                }

                const options = {
                    clientSecret: json.client_secret, appearance: JSON.parse(this.container.dataset.appearance),
                };

                this.createStripeElementsForm(options);


                this.container.querySelector('.stripe-payment-elements-submit-button').addEventListener('click', async (event) => {
                    event.preventDefault();
                    this.container.classList.add('hidden');
                    const elements = this.elements;
                    const form = this.getFormData();
                    const formDataArray = [...form.entries()];
                    const params = formDataArray
                        .map((x) => `${encodeURIComponent(x[0])}=${encodeURIComponent(x[1])}`)
                        .join('&');

                    this.stripeInstance
                        .confirmSetup({
                            elements, confirmParams: {
                                return_url: `${this.container.dataset.confirmSetupIntentUrl}&${params}`,
                            },
                        })
                        .then((result) => {
                            if (result.error) {
                                this.showErrorMessage(result.error.message);
                            }
                        });
                });
            });
    }

    cartPaymentFlow() {
        const form = this.getFormData();
        let responseError = false;

        fetch(window.location.href, {
            method: 'post', body: form, headers: {
                Accept: 'application/json',
            },
        })
            .then((res) => {
                if (res.status !== 200) {
                    responseError = true;
                }
                return res.json();
            })
            .then((json) => {
                if (responseError) {
                    this.container.classList.add('hidden');
                    this.showErrorMessage(json.message);
                    return;
                }


                const completeActionUrl = new URL(this.completeActionUrl);
                completeActionUrl.searchParams.append('commerceTransactionHash', json.transactionHash);
                completeActionUrl.searchParams.append('commerceTransactionId', json.transactionId);
                this.completeActionUrl = completeActionUrl.toString();

                const options = {
                    clientSecret: json.redirectData.client_secret,
                    appearance: JSON.parse(this.container.dataset.appearance),
                };

                this.createStripeElementsForm(options);

                const updatePaymentIntentForm = new FormData();
                updatePaymentIntentForm.append('action', 'commerce-stripe/payments/save-payment-intent');
                updatePaymentIntentForm.append(window.csrfTokenName, window.csrfTokenValue);
                updatePaymentIntentForm.append('paymentIntentId', json.redirectData.payment_intent);
                updatePaymentIntentForm.append('gatewayId', this.container.dataset.gatewayId);

                const savePaymentSourceCheckbox = this.container.closest('form').querySelector('input[name="savePaymentSource"]');
                savePaymentSourceCheckbox.addEventListener('click', function () {
                    updatePaymentIntentForm.append('paymentIntent[setup_future_usage]', savePaymentSourceCheckbox.checked ? '1' : '0');

                    fetch(window.location.href, {
                        method: 'post', body: updatePaymentIntentForm, headers: {
                            Accept: 'application/json',
                        },
                    }).then(response => response.json()).then(data => {
                        this.elements.fetchUpdates();
                    }).catch(error => {
                        console.error('There was an error updating the Payment Intent:', error);
                    });
                }.bind(this));

                this.container.querySelector('.stripe-payment-elements-submit-button').addEventListener('click', async (event) => {
                    event.preventDefault();

                    this.fade(this.container, true);
                    this.container.parentNode.querySelector('.stripe-payment-elements-processing-button').classList.remove('hidden');
                    const elements = this.elements;
                    const {error} = await this.stripeInstance.confirmPayment({
                        elements, confirmParams: {
                            'return_url': this.completeActionUrl,
                        },
                    });
                    this.container.parentNode.querySelector('.stripe-payment-elements-processing-button').classList.add('hidden');
                    this.showErrorMessage(error.message);
                });

            });


    }

    fade(element, fadeOut, skipFade) {
        if (skipFade) {
            element.style.opacity = fadeOut ? 0 : 1;
            return;
        }

        let opacity = fadeOut ? 1 : 0;
        const step = 0.1; // Adjust the step value for smoother or faster fade-in/fade-out

        const interval = setInterval(() => {
            if (fadeOut && opacity > 0) {
                opacity -= step;
                element.style.opacity = opacity;
            } else if (!fadeOut && opacity < 1) {
                opacity += step;
                element.style.opacity = opacity;
            } else {
                clearInterval(interval);
            }
        }, 50); // Adjust the interval value for smoother or faster fade-in/fade-out
    }

    handle() {
        const formData = this.getFormData();
        const action = formData.get('action');

        if (action.includes('commerce/payments/pay')) {
            this.cartPaymentFlow();
        } else if (action.includes('commerce/payment-sources/add') || action.includes('commerce/subscriptions/create')) {
            this.setupIntentFlow();
        }
    }
}

function initStripe() {
    if (typeof Stripe === 'undefined') {
        setTimeout(initStripe, 200);
    } else {
        document.querySelectorAll('.stripe-payment-elements-form').forEach((container) => {
            const handlerInstance = new PaymentIntentsElements(container.dataset.publishablekey, container);
            container.dataset.handlerInstance = handlerInstance;
            handlerInstance.handle();
        });
    }
}

initStripe();
