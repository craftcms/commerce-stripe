function PaymentIntentsElements(publishableKey, container) {
    this.container = container;
    this.formNamespace = this.container.dataset.paymentFormNamespace;
    this.stripeInstance = Stripe(publishableKey);
    this.elements = null;
    this.completeActionUrl = this.container.dataset.completeActionUrl;
    const self = this;

    this.displayPaymentForm = function () {
        const form = new FormData(this.container.closest("form"));

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
            let completeActionUrl = new URL(self.completeActionUrl);
            completeActionUrl.searchParams.append('commerceTransactionHash', json.transactionHash);
            completeActionUrl.searchParams.append('commerceTransactionId', json.transactionId);
            self.completeActionUrl = completeActionUrl.toString();

            const options = {
                clientSecret: json.redirectData.client_secret,
                appearance: JSON.parse(self.container.dataset.appearance)
            };

            self.elements = self.stripeInstance.elements(options);

            const paymentElement = self.elements.create('payment', {
                layout: JSON.parse(self.container.dataset.layout),
            });
            const paymentElementDiv = self.container.querySelector('.stripe-payment-element');
            paymentElement.mount(paymentElementDiv);

            self.container.classList.remove('hidden');
        });
    }

    this.container.querySelector('.stripe-payment-elements-submit-button').addEventListener('click', async (event) => {
        event.preventDefault();
        let elements = self.elements;
        const {error} = await self.stripeInstance.confirmPayment({
            elements,
            confirmParams: {
                return_url: self.completeActionUrl,
            },
        });

        self.container.querySelector('.stripe-error-message').classList.remove('hidden');
        self.container.querySelector('.stripe-error-message').innerText = error.message;
    });
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

            handlerInstance.displayPaymentForm();
        });
    }
}

initStripe();
