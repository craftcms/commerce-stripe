function initStripe() {
    if (typeof Stripe === "undefined") {
        setTimeout(initStripe, 200);
    } else {
        $('.stripe-form').each(function() {
            $container = $(this);

            function updateErrorMessage(event) {
                var displayError = $('.card-errors', $container).get(0);

                if (event.error) {
                    displayError.textContent = event.error.message;
                } else {
                    displayError.textContent = '';
                }

                $('.modal').data('modal').updateSizeAndPosition();
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
                hidePostalCode: true});

            card.addEventListener('change', updateErrorMessage);

            // Add an instance of the card Element into the `card-element` <div>
            card.mount($('.card-data', $container).empty().get(0));

            $form = $('form', $container);

            $form.on('submit', function (ev) {
                ev.preventDefault();

                stripe.createSource(card).then(function(result) {
                    if (result.error) {
                        updateErrorMessage(result);
                    } else {
                        if (result.source.card.three_d_secure === "required" || (result.source.card.three_d_secure === "optional" && $container.data('enforce3dsecure')))
                        {
                            // 3D secure
                        } else {
                            $form.append($('<input type="hidden" name="stripeToken"/>').val(result.source.id));
                            $form.get(0).submit();
                        }
                    }
                });
            });

            $('.modal').data('modal').updateSizeAndPosition();
        });
    }
}

initStripe();