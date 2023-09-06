<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Stripe for Craft Commerce icon"></p>

<h1 align="center">Stripe for Craft Commerce</h1>

This plugin provides a [Stripe](https://stripe.com/) integration for [Craft Commerce](https://craftcms.com/commerce)
supporting [Payment Intents](https://stripe.com/docs/payments/payment-intents) and traditional charges.

## Requirements

- Craft CMS 4.0 or later
- Craft Commerce 4.3 or later
- Stripe [API version](https://stripe.com/docs/api/versioning) '2019-03-14'

## Installation

You can install this plugin from the Plugin Store or using Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s control panel, search for “Stripe for Craft Commerce”, and choose **Install**
in the plugin’s modal window.

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

## Setup

To add the Stripe payment gateway in the Craft control panel, navigate to **Commerce** → **Settings** → **Gateways**,
create a new gateway, and set the gateway type to “Stripe Payment Intents”.

In order for the gateway to work properly, the following settings are required:

- Publishable API Key
- Secret API Key
- Webhook Secret

You can find these in your Stripe dashboard under **Developers** → **API keys**.

We recommend using environment variables to store these values and using the environment variable names in the gateway
settings.

Once you have saved the the gateway in Commerce, you can reopen it to find the webhook URL available for you to enter
into
Stripe’s Dashboard settings.

When you've set up that URL in the Stripe dashboard, you can view the signing secret in its settings. Enter this value
in your Stripe
gateway settings in the Webhook Signing Secret field. To use webhooks, the Webhook Signing Secret setting is required.

We recommend emitting all possible events for the webhook. Unnecessary events will be ignored by the plugin.

We advise using the Stripe CLI in development to test webhooks.
See [Testing Webhooks](https://stripe.com/docs/webhooks/test) for more information.

## Payment Form Changes in 4.0

Previously the `gateway.getPaymentFormHtml()` method output a basic credit card stripe form to generate a payment method
ID
on the client side and submitted that ID to the `commerce/payments/pay` action.

This continues to work for backwards compatibility, so if you had a custom stripe payment form that will continue to
work.

Now the `gateway.getPaymentFormHtml()` outputs a payment elements form that supports all payment methods in addition to
just credit cards.
It does this by first submitting an automatic request to `commerce/payments/pay` (without a payment method), which
creates a transaction with
a redirect status and returns a reference to the generated payment intent and client secret.

This allows the new form to support all payment methods, including Apple Pay and Google Pay, which require a payment
intent to be created.

Details on how to configure the new payment form are below.

## Configuration Settings

### `chargeInvoicesImmediately`

For subscriptions with automatic payments, Stripe creates an invoice 1-2 hours before attempting to charge it. By
setting this to `true` in your `commerce-stripe.php` config file, you can force Stripe to charge this invoice
immediately.

This setting affects all Stripe gateways on your Commerce installation.

## Subscriptions

### Creating a Subscription Plan

1. Every subscription plan must first
   be [created in the Stripe dashboard](https://dashboard.stripe.com/test/subscriptions/products).
2. In the Craft control panel, navigate to **Commerce** → **Settings** → **Subscription plans** and create a new
   subscription plan.

### Subscribe Options

#### `trialDays`

When subscribing, you can pass a `trialDays` parameter. The first full billing cycle will start once the number of trial
days lapse. Default value is `0`.

### Cancel Options

#### `cancelImmediately`

If this parameter is set to `true`, the subscription is canceled immediately. Otherwise, it is marked to cancel at the
end of the current billing cycle. Defaults to `false`.

### Plan-Switching Options

#### `prorate`

If this parameter is set to `true`, the subscription switch will
be [prorated](https://stripe.com/docs/subscriptions/upgrading-downgrading#understanding-proration). Defaults to `false`.

#### `billImmediately`

If this parameter is set to `true`, the subscription switch is billed immediately. Otherwise, the cost (or credit,
if `prorate` is set to `true` and switching to a cheaper plan) is applied to the next invoice.

> ⚠️ If the billing periods differ, the plan switch will be billed immediately and this parameter will be ignored.

## Events

The plugin provides several events you can use to modify the behavior of your integration.

### Payment Request Events

#### `buildGatewayRequest`

Plugins get a chance to provide additional metadata to any request that is made to Stripe in the context of paying for
an order. This includes capturing and refunding transactions.

There are some restrictions:

- Changes to the `Transaction` model available as the `transaction` property will be ignored;
- Changes to the `order_id`, `order_number`, `transaction_id`, `client_ip`, and `transaction_reference` metadata keys
  will be ignored;
- Changes to the `amount`, `currency` and `description` request keys will be ignored;

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

Plugins get a chance to do something whenever a webhook is received. This event will be fired regardless of whether or
not the gateway has done something with the webhook.

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

## Billing Portal

You can now generate a link to the stripe billing portal for customers to manage their credit cards and plans.

```twig
<a href={{ gateway.billingPortalUrl(currentUser) }}">Manage your billing account</a>
```

You can also pass a `returnUrl` parameter to redirect the customer to a specific page after they have finished.

```twig
{{ gateway.billingPortalUrl(currentUser, 'myaccount') }}
```

You can also pass a `configurationId` parameter to use a
specific [Stripe configuration](https://stripe.com/docs/api/customer_portal/configuration).

```twig
{{ gateway.billingPortalUrl(currentUser, 'myaccount', 'config_12345') }}
```

You can also redirect the customer to the billing portal using the `commerce-stripe/customers/billing-portal-redirect`
action in a form,
but this does not allow a `configurationId` to be passed, only a redirect URL.

## Syncing Customer Payment Methods

Going forward the plugin will sync payment methods that are attached to customers in Stripe with the payment sources
inside your installation. Make sure your webhook is set up correctly in Stripe to sync correctly.

To do an initial sync, you can use the `commerce-stripe/sync/payment-methods` console command.

```bash

## Creating a Stripe Payment Form

You can output a standard credit card form quickly using `order.gateway.getPaymentFormHtml()`
or `gateway.getPaymentFormHtml()`.

The payment for HTML output the inputs and includes the javascript needed to complete the form you are embedding it in.
You can embed it inside one of three forms:

- `commerce/payments/pay`.
- `commerce/payment-sources/add`.
- `commerce/subscriptions/subscribe`. (You should only output the payment form HTML in this form if they do not have a
  primary payment source already set up)

If you embed it into a form tag with an action parameter of `commerce/payments/pay`, it will be a payment flow which
will create a transaction in Commerce with a redirect status and create a payment intent in Stripe.

If you want to read how to create the legacy payment form with stripe.js read
the [old README](https://github.com/craftcms/commerce-stripe/blob/e0325e98594cc4824b3e2788ac0573c8d04a71d5/README.md#creating-a-stripe-payment-form-for-the-payment-intents-gateway).

## Customizing the Stripe Payment Form

`getPaymentFormHtml()` takes the following parameters:

### `paymentFormType`

This option has 3 possible values: `card` (default), `elements` and `checkout`.

#### `card` (default)

This renders a Stripe Elements form with a credit card number, expiry date and CVC input fields. It will also
show the [Stripe Link](https://stripe.com/docs/payments/link) feature if you have not turned it off in your dashboard.

This option does not let you pass the `paymentMethods` array to the form.

Example:

```twig
{% set params = {
  paymentFormType: 'card',
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
```

#### `elements`

This renders a Stripe Elements form with all payment methods you have enabled in your Stripe Dashboard, like Apple Pay
or Afterpay.

This option does lets you pass the `paymentMethods` array which will override automatic payment methods you
have enabled in your Stripe dashboard.

```twig
{% set params = {
  paymentFormType: 'elements',
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
```

Remember, some payment methods like Apple Pay will only show up in the Safari browser.

#### `checkout`

This generates a form ready to redirect to Stripe Checkout. This can only be used inside a `commerce/payments/pay` form.

This option ignores all other params.

```twig
{% set params = {
  paymentFormType: 'checkout',
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
```

### `paymentMethods`

You can pass an array of payment methods when using the `elements` payment form type. This will override the automatic
payment methods you have enabled in your dashboard.

```twig
{% set params = {
  paymentFormType: 'elements',
  paymentMethods: ['card', 'sepa_debit'],
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
```

### `appearance`

You can pass an array of appearance options when using the `elements` or `card` payment form type.

This expects data for the [Elements Appearance API](https://stripe.com/docs/elements/appearance-api).

```twig
{% set params = {
  paymentFormType: 'elements',
  appearance: {
    theme: 'stripe'
  }
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
```

```twig
{% set params = {
  paymentFormType: 'elements',
  appearance: {
    theme: 'night',
    variables: {
      colorPrimary: '#0570de'
    }
  }
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
```

### `elementOptions`

This allows you to modify
the [Payment Element options](https://stripe.com/docs/js/elements_object/create_payment_element#payment_element_create-options)

```twig
{% set params = {
  paymentFormType: 'elements',
  elementOptions: {
    layout: {
      type: 'tabs',
      defaultCollapsed: false,
      radios: false,
      spacedAccordionItems: false
    }
  }
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
```

Default value:

```twig
elementOptions: {
    layout: {
      type: 'tabs'
    }
  }
```

### `submitButtonClasses` and `submitButtonText`

These control the button rendered at the bottom of the form.

```twig
{% set params = {
  paymentFormType: 'elements',
  submitButtonClasses: 'cursor-pointer rounded px-4 py-2 inline-block bg-blue-500 hover:bg-blue-600 text-white hover:text-white my-2',
  submitButtonText: 'Pay',
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
```

### `errorMessageClasses`

Above the form is a location where error messages are displayed when an error occurs. You can control the classes of
this element.

```
{% set params = {
  paymentFormType: 'elements',
  submitButtonClasses: 'cursor-pointer rounded px-4 py-2 inline-block bg-blue-500 hover:bg-blue-600 text-white hover:text-white my-2',
  submitButtonText: 'Pay',
  errorMessageClasses: 'bg-red-200 text-red-600 my-2 p-2 rounded',
} %}
{{ cart.gateway.getPaymentFormHtml(params) }}
  
```
