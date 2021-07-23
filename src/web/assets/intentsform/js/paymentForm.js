function PaymentIntents(publishableKey, container) {
  this.container = container;
  this.stripeInstance = Stripe(publishableKey);
  
  this.perform3dsAuthentication = function(card)  {
    
    this.displayMessage('Please wait, processing payment...');
    this.stripeInstance.handleCardPayment(this.container.data('client-secret'), card).then(function(result) {
      if (result.error) {
        this.displayMessage(result.error.message);
        this.displayPaymentForm();
      } else {
        location.reload();
      }
    }.bind(this));
  };
  
  this.displayStripeMessage = function(event) {
    if (event.error) {
      this.displayMessage(event.error.message);
    } else {
      this.displayMessage('');
    }
  };
  
  this.displayMessage = function(message) {
    var messageContainer = $('.card-errors', this.container).get(0);
    messageContainer.textContent = message;
    
    if ($('.modal').data('modal')) {
      $('.modal').data('modal').updateSizeAndPosition();
    }
  };
  
  this.displayPaymentForm = function() {
    $('.payment-form-fields').removeClass('hidden');
    
    var elements = this.stripeInstance.elements();
    
    var style = {
      base: {
        // Add your base input styles here. For example:
        fontSize: '16px',
        lineHeight: '21px'
      }
    };
    
    // Create an instance of the card Element
    var card = elements.create('card', {
      style: style,
      hidePostalCode: true
    }
    );
    
    card.addEventListener('change', this.displayStripeMessage.bind(this));
    
    // Add an instance of the card Element into the `card-element` <div>
    card.mount($('.card-data', this.container).empty().get(0));
    
    var $form = $('form', this.container);
    
    if ($form.length === 0) {
      $form = this.container.parents('form');
    }
    
    // Remove already bound events
    $form.off('submit');
    $form.data('processing', false);
    
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
          'state': $('input[name=stripe-state]', $form).val(),
        };
      }
      
      
      // If client secret is present, that's a pretty good indicator that things should be handled on page.
      if (this.container.data('client-secret')) {
        this.perform3dsAuthentication(card);
        
        return;
      }
      
      // This section is for handling things on server end
      orderEmail = $('input[name=orderEmail]').val();
      var paymentData = {
        billing_details: {
          name: cardHolderName,
          email: orderEmail,
          address: ownerAddress
        }
      };
      
      // Tokenize the credit card details and create a payment source
      this.stripeInstance.createPaymentMethod('card', card, paymentData).then(function(result) {
        if (result.error) {
          this.displayMessage(result.error.message);
          $form.data('processing', false);
        } else {
          // Add the payment source token to the form.
          $form.append($('<input type="hidden" name="paymentMethodId"/>').val(result.paymentMethod.id));
          $form.get(0).submit();
        }
      }.bind(this));
    }.bind(this));
    
    if ($('.modal').data('modal')) {
      $('.modal').data('modal').updateSizeAndPosition();
    }
  };
};

function initStripe() {
  // Because this might get executed before Stripe is loaded.
  if (typeof Stripe === "undefined") {
    setTimeout(initStripe, 200);
  } else {
    $('.stripe-payment-intents-form').each(function() {
      $container = $(this);
      
      var handlerInstance = new PaymentIntents($container.data('publishablekey'), $container);
      $container.data('handlerInstance', handlerInstance);
      
      if ($container.data('scenario') == 'payment') {
        handlerInstance.displayPaymentForm();
      }
      
      if ($container.data('scenario') == '3ds') {
        handlerInstance.perform3dsAuthentication();
      }
      
    });
  }
}

initStripe();
