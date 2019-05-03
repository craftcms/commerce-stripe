function initStripe() {
    // Because this might get executed before Stripe is loaded.
    if (typeof Stripe === "undefined") {
        setTimeout(initStripe, 200);
    } else {
        $('.stripe-payment-intents-form').each(function() {
            $container = $(this);

            function updateErrorMessage(event) {
                var displayError = $('.card-errors', $container).get(0);

                if (event.error) {
                    displayError.textContent = event.error.message;
                } else {
                    displayError.textContent = '';
                }

                if ($('.modal').data('modal')) {
                    $('.modal').data('modal').updateSizeAndPosition();
                }
            }

            var stripe = Stripe($container.data('publishablekey'));
            var elements = stripe.elements();

            var style = {
                base: {
                    // Add your base input styles here. For example:
                    fontSize: '14px',
                    lineHeight: '21px'
                }
            };

            // Create an instance of the card Element
            var card = elements.create('card', {
                    style: style,
                    hidePostalCode: true
                }
            );

            card.addEventListener('change', updateErrorMessage);

            // Add an instance of the card Element into the `card-element` <div>
            card.mount($('.card-data', $container).empty().get(0));

            var $form = $('form', $container);

            if ($form.length === 0) {
                $form = $container.parents('form');
            }

            // Remove already bound events
            $form.off('submit');

            $form.on('submit', function(ev) {
                ev.preventDefault();

                // If form submitted already, disregard.
                if ($form.data('processing')) {
                    return false;
                }

                $form.data('processing', true);

                // Compose card holder info
                var cardHolderName, orderEmail, ownerAddress;

                if ($('.card-holder-first-name', $form).length > 0 && $('.card-holder-last-name', $form).length > 0) {
                    cardHolderName = $('.card-holder-first-name', $form).val() + ' ' + $('.card-holder-last-name', $form).val();
                }

                if ($('.stripe-address', $form).length > 0) {
                    ownerAddress = {
                        'line1': $('input[name=stripe-line1]', $form).val(),
                        'city': $('input[name=stripe-city]', $form).val(),
                        'postal_code': $('input[name=stripe-postal-code]', $form).val(),
                        'country': $('input[name=stripe-country]', $form).val(),
                    };
                }

                orderEmail = $('input[name=orderEmail]').val();

                // Tokenize the credit card details and create a payment source
                stripe.createPaymentMethod('card', card, {billing_details: {name: cardHolderName, email: orderEmail, address: ownerAddress}}).then(function(result) {
                    if (result.error) {
                        updateErrorMessage(result);
                        $form.data('processing', false);
                    } else {
                        // Add the payment source token to the form.
                        $form.append($('<input type="hidden" name="paymentMethodId"/>').val(result.paymentMethod.id));
                        $form.get(0).submit();
                    }
                });
            });

            if ($('.modal').data('modal')) {
                $('.modal').data('modal').updateSizeAndPosition();
            }
        });
    }
}

initStripe();
