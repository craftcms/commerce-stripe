<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Stripe for Craft Commerce icon"></p>

<h1 align="center">Stripe for Craft Commerce</h1>

This plugin provides a [Stripe](https://stripe.com/) integration for [Craft Commerce](https://craftcms.com/commerce) supporting [Payment Intents](https://stripe.com/docs/payments/payment-intents) and traditional charges.

## Requirements

- Craft CMS 3.1.5 or later
- Craft Commerce 2.2 or later
- Stripe [API version](https://stripe.com/docs/api/versioning) '2019-03-14'

## Installation

You can install this plugin from the Plugin Store or using Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s control panel, search for “Stripe for Craft Commerce”, and choose **Install** in the plugin’s modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftcms/commerce-stripe

# tell Craft to install the plugin
php craft install/plugin commerce-stripe
```

## Changes in 2.0

We deprecated the original “Stripe” gateway as “Stripe Charge” and added a new “Stripe Payment Intents” gateway which uses Stripe’s [Payment Intents API](https://stripe.com/docs/payments/payment-intents) and [3D Secure 2](https://stripe.com/guides/3d-secure-2), which is easier to implement than the old 3D Secure standard and delivers a better customer experience.

**Stripe began declining all EU charges using its old charge API on September 14, 2019,** so we highly recommend switching to the newer “Stripe Payment Intents” gateway. (Learn more by reading Stripe’s [Strong Customer Authentication](https://stripe.com/guides/strong-customer-authentication) guide.)

## Setup

To add the Stripe payment gateway in the Craft control panel, navigate to **Commerce** → **Settings** → **Gateways**, create a new gateway, and set the gateway type to “Stripe Payment Intents”.
 
 > ⚠️ The deprecated “Stripe Charge” gateway is still available. See [Changes in 2.0](#changes-in-2-0).
 
In order for the gateway to work properly, the following settings are required:
 
* Publishable API Key
* Secret API Key

You can find these in your Stripe dashboard under **Developers** → **API keys**.

## Payment Process and Security

This plugin relies on stored payment methods and doesn’t allow directly-submitted credit card details. To check out, a customer must submit a  `paymentMethodId` parameter for the Commerce `commerce/payments/pay` form action. Example below.

## How to create the Stripe payment form for the Payment Intents gateway.

If you are wanting to make a custom Stripe payment form, follow the instructions below.

Alternatively, you can output the standard form with `order.gateway.getPaymentFormHtml()` or `gateway.getPaymentFormHtml()` and not follow these steps in your template..

1) Include the stripe.js `<script src="https://js.stripe.com/v3/"></script>` script into the payment page.

See the [Stripe JS](https://stripe.com/docs/js) docs for more information on the basics of using the Stripe javascript libraries and Stripe Elements front end tools used below.
 
2) Create the basic HTML that the stripe inputs will be loaded into.

(Replace the `<<INSERT YOUR GATEWAY ID HERE>>` with your Stripe payment intents gateway ID)

You do not need his hidden `gatewayId` input if the gateway is already saved to the cart.

```twig
<form method="post" action="" id="payment-form">
    {{ actionInput('commerce/payments/pay') }}
    {{ redirectInput(siteUrl('shop/customer/order', { number: cart.number, success: 'true' })) }}
    {{ hiddenInput('cancelUrl', siteUrl('shop/checkout/payment')|hash) }}
    {{ csrfInput() }}

    <div class="form-row">
        <label for="card-element">
            Credit or debit card inputs will be loaded in here with stripe element JS
        </label>
        <div id="card-element">
            <!-- A Stripe Element will be inserted here. -->
        </div>
        <!-- Used to display form errors. -->
        <div id="card-errors" role="alert"></div>
    </div>

    <button id="submit-button" type="submit">Submit Payment</button>
</form>
```
2) Create an instance of the Stripe JS library with the `publishableKey` from your Stripe gateway settings.

In you page's javascript create the object:

```javascript
var stripe = Stripe('{{ parseEnv(cart.gateway.publishableKey) }}');
```

Create an instance of Stripe elements:

```javascript
// Create an instance of Elements.
var elements = stripe.elements();
```

Set up the style attributes of the card element we are going to create

```javascript
// Custom styling can be passed to options when creating an Element.
// (Note that this demo uses a wider set of styles than the guide below.)
var style = {
  base: {
    color: '#32325d',
    fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
    fontSmoothing: 'antialiased',
    fontSize: '16px',
    '::placeholder': {
      color: '#aab7c4'
    }
  },
  invalid: {
    color: '#fa755a',
    iconColor: '#fa755a'
  }
};
```

Create the cart insance using the style:

```javascript
// Create an instance of the card Element.
var card = elements.create('card', {style: style});
```

Mount the card into the ` <div id="card-element">` in our html we created above:

```javascript
// Add an instance of the card Element into the `card-element` <div>.
card.mount('#card-element');
```

Handle real-time validation errors from the card Element being updated. We will add the error messages in realtime to the `<div id="card-errors" role="alert"></div>` we created above:

```javascript
card.on('change', function(event) {
    var displayError = document.getElementById('card-errors');
    if (event.error) {
        displayError.textContent = event.error.message;
    } else {
        displayError.textContent = '';
    }
});
```

Set up the form listener to create a stripe payment method from the card element, as well as passing in optional billing details when creating the payment method:

```javascript
var form = document.getElementById('payment-form');
form.addEventListener('submit', function(event) {
    event.preventDefault();

    var paymentData = {
        billing_details: {
            email: "{{ cart.email }}",
        }
    };

    // https://stripe.com/docs/js/payment_methods/create_payment_method
    stripe.createPaymentMethod('card', card, paymentData).then(function(result) {
        if (result.error) {
            // Inform the user if there was an error.
            var errorElement = document.getElementById('card-errors');
            errorElement.textContent = result.error.message;
        } else {
            // Insert the token ID into the form so it gets submitted to the server
            var form = document.getElementById('payment-form');
            var hiddenInput = document.createElement('input');
            hiddenInput.setAttribute('type', 'hidden');
            hiddenInput.setAttribute('name', 'paymentMethodId'); // This is the only thing that needs to be submitted to the craft server.
            hiddenInput.setAttribute('value', result.paymentMethod.id);
            form.appendChild(hiddenInput);

            // Submit the form
            form.submit();
        }
    });
});
```

## Webhooks

You’ll need to update configuration with this plugin and the Stripe dashboard in order to utilize webhooks.

### Configuring Stripe

Set up a webhook endpoint in your Stripe dashboard API settings. The URL for this endpoint can be found in your Commerce Stripe gateway settings.

We recommend emitting all possible events, but the required events are:

#### For 3D Secure Payments (if using Stripe Charge gateway)

 * `source.cancelled`
 * `source.chargeable`
 * `source.failed`

#### For Subscriptions

* `invoice.payment_succeeded`
* `customer.subscription.deleted`

We strongly recommended enabling the following events to ensure your Commerce subscriptions stay in sync with your Stripe dashboard:

* `plan.deleted`
* `plan.updated`
* `invoice.created`
* `customer.subscription.updated`
* `invoice.payment_failed`

### Configuring the Gateway

When you've set up the endpoint, you can view the signing secret in its settings. Enter this value in your Stripe gateway settings in the Webhook Signing Secret field. To use webhooks, the Webhook Signing Secret setting is required.

## Configuration Settings

### `chargeInvoicesImmediately`

For subscriptions with automatic payments, Stripe creates an invoice 1-2 hours before attempting to charge it. By setting this to `true` in your `commerce-stripe.php` config file, you can force Stripe to charge this invoice immediately.

This setting affects all Stripe gateways on your Commerce installation.

## Subscriptions

### Creating a Subscription Plan

1. Every subscription plan must first be [created in the Stripe dashboard](https://dashboard.stripe.com/test/subscriptions/products).
2. In the Craft control panel, navigate to **Commerce** → **Settings** → **Subscription plans** and create a new subscription plan.

### Subscribe Options

#### `trialDays`

When subscribing, you can pass a `trialDays` parameter. The first full billing cycle will start once the number of trial days lapse. Default value is `0`.

### Cancel Options

#### `cancelImmediately`

If this parameter is set to `true`, the subscription is canceled immediately. Otherwise, it is marked to cancel at the end of the current billing cycle. Defaults to `false`.

### Plan-Switching Options

#### `prorate`

If this parameter is set to `true`, the subscription switch will be [prorated](https://stripe.com/docs/subscriptions/upgrading-downgrading#understanding-proration). Defaults to `false`.

#### `billImmediately`

If this parameter is set to `true`, the subscription switch is billed immediately. Otherwise, the cost (or credit, if `prorate` is set to `true` and switching to a cheaper plan) is applied to the next invoice.

> ⚠️ If the billing periods differ, the plan switch will be billed immediately and this parameter will be ignored.

## Events

The plugin provides several events you can use to modify the behavior of your integration.

### Payment Request Events

#### `buildGatewayRequest`

Plugins get a chance to provide additional metadata to any request that is made to Stripe in the context of paying for an order. This includes capturing and refunding transactions.

There are some restrictions:
* Changes to the `Transaction` model available as the `transaction` property will be ignored;
* Changes to the `order_id`, `order_number`, `transaction_id`, `client_ip`, and `transaction_reference` metadata keys will be ignored;
* Changes to the `amount`, `currency` and `description` request keys will be ignored;

```php
use craft\commerce\models\Transaction;
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\base\Gateway as StripeGateway;
use yii\base\Event;

Event::on(
    StripeGateway::class, 
    StripeGateway::EVENT_BUILD_GATEWAY_REQUEST, 
    function(BuildGatewayRequestEvent $e) {
        /** @var Transaction $transaction */
        $transaction = $e->transaction;
        
        if ($transaction->type === 'refund') {
            $e->request['someKey'] = 'some value';
        }
    }
);
```

#### `receiveWebhook`

Plugins get a chance to do something whenever a webhook is received. This event will be fired regardless of whether or not the gateway has done something with the webhook.

```php
use craft\commerce\stripe\events\ReceiveWebhookEvent;
use craft\commerce\stripe\base\Gateway as StripeGateway;
use yii\base\Event;

Event::on(
    StripeGateway::class, 
    StripeGateway::EVENT_RECEIVE_WEBHOOK, 
    function(ReceiveWebhookEvent $e) {
        if ($e->webhookData['type'] == 'charge.dispute.created') {
            if ($e->webhookData['data']['object']['amount'] > 1000000) {
                // Be concerned that a USD 10,000 charge is being disputed.
            }
        }
    }
);
```

### Subscription Events

#### `createInvoice`

Plugins get a chance to do something when an invoice is created on the Stripe gateway.

```php
use craft\commerce\stripe\events\CreateInvoiceEvent;
use craft\commerce\stripe\base\SubscriptionGateway as StripeGateway;
use yii\base\Event;

Event::on(
    StripeGateway::class, 
    StripeGateway::EVENT_CREATE_INVOICE, 
    function(CreateInvoiceEvent $e) {
        if ($e->invoiceData['billing'] === 'send_invoice') {
            // Forward this invoice to the accounting department.
        }
    }
);
```

#### `beforeSubscribe`

Plugins get a chance to tweak subscription parameters when subscribing.

```php
use craft\commerce\stripe\events\SubscriptionRequestEvent;
use craft\commerce\stripe\base\SubscriptionGateway as StripeGateway;
use yii\base\Event;

Event::on(
    StripeGateway::class, 
    StripeGateway::EVENT_BEFORE_SUBSCRIBE, 
    function(SubscriptionRequestEvent $e) {
        $e->parameters['someKey'] = 'some value';
        unset($e->parameters['unneededKey']);
    }
);
```

### Deprecated Events

The following event is deprecated because it’s associated with the deprecated Stripe Charge gateway.

#### `receive3dsPayment`

Plugins get a chance to do something whenever a successful 3D Secure payment is received.

```php
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\events\Receive3dsPaymentEvent;
use craft\commerce\stripe\gateways\Gateway as StripeGateway;
use yii\base\Event;

Event::on(
    StripeGateway::class, 
    StripeGateway::EVENT_RECEIVE_3DS_PAYMENT, 
    function(Receive3dsPaymentEvent $e) {
        $order = $e->transaction->getOrder();
        $paidStatus = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle('paid');
        if ($order && $paidStatus && $order->orderStatusId !== $paidStatus->id && $order->getIsPaid()) {
            $order->orderStatusId = $paidStatus->id;
            Craft::$app->getElements()->saveElement($order);
        }
    }
);
```
