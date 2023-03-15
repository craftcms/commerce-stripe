function PaymentIntentsElements(publishableKey, container) {
    this.container = container;
    this.formNamespace = this.container.data('payment-form-namespace');
    this.stripeInstance = Stripe(publishableKey);
    this.elements = null;
    this.completeActionUrl = this.container.data('complete-action-url');
    const self = this;

    this.displayPaymentForm = function () {
        const form = new FormData(this.container.closest("form").get(0));
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
                appearance: self.container.data('appearance')
            };

            self.elements = self.stripeInstance.elements(options);

            const paymentElement = self.elements.create('payment', {
                layout: self.container.data('layout'),
            });
            const paymentElementDiv = self.container.find('.stripe-payment-element');
            paymentElement.mount(paymentElementDiv.get(0));

            self.container.removeClass('hidden');
        });
    }

    this.container.find('.stripe-payment-elements-submit-button').click(async (event) => {
        event.preventDefault();
        let elements = self.elements;
        const {error} = await self.stripeInstance.confirmPayment({
            elements,
            confirmParams: {
                return_url: self.completeActionUrl,
            },
        });

        if (error.type === "card_error" || error.type === "validation_error") {
            alert(error.message);
        } else {
            alert("An unexpected error occurred.");
        }
    });
}

function initStripe() {
    if (typeof Stripe === 'undefined') {

        setTimeout(initStripe, 200);
    } else {
        $('.stripe-payment-elements-form').each(function () {
            let $container = $(this);

            let handlerInstance = new PaymentIntentsElements(
                $container.data('publishablekey'),
                $container
            );
            $container.data('handlerInstance', handlerInstance);

            handlerInstance.displayPaymentForm();
        });
    }
}

initStripe();