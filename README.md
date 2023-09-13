<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Stripe for Craft Commerce icon"></p>

<h1 align="center">Stripe for Craft Commerce</h1>

Flexible payment processing for your [Craft Commerce](https://craftcms.com/commerce) store, powered by [Stripe](https://stripe.com/).

This plugin provides a [gateway](https://craftcms.com/docs/commerce/4.x/payment-gateways.html) that leverages the [Payment Intents](https://stripe.com/docs/payments/payment-intents) API to support popular payment methods like…

- Major debit and credit cards
- Apple Pay
- Google Pay
- Cash App
- Afterpay, Affirm, and other installment plans
- ECH and direct bank account transfers

…and [more](https://stripe.com/guides/payment-methods-guide)!

> [!NOTE]
> Looking for [3.x documentation](https://github.com/craftcms/commerce-stripe/tree/v3)?

## Requirements

- Craft CMS 4.0 or later
- Craft Commerce 4.3 or later
- Stripe [API version](https://stripe.com/docs/api/versioning) `2022-11-15`

## Installation

You can install this plugin from the [Plugin Store](https://plugins.craftcms.com/commerce-stripe) or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s control panel, search for “Stripe for Craft Commerce”, and click **Install** in the sidebar.

#### With Composer

Open your terminal and run the following commands:

```bash
# Switch your project’s directory:
cd /path/to/my-project

# Require the package with Composer:
composer require craftcms/commerce-stripe

# Install the plugin with Craft:
php craft install/plugin commerce-stripe
```

## Setup

To add a Stripe payment gateway, open the Craft control panel, navigate to **Commerce** → **System Settings** → **Gateways**, and click **+ New gateway**.

Your gateway’s **Name** should make sense to administrators _and_ customers (especially if you’re using the example templates).

### Secrets

From the **Gateway** dropdown, select **Stripe**, then provide the following information:

- Publishable API Key
- Secret API Key
- Webhook Signing Secret (See [Webhooks](#webhooks) for details)

Your **Publishable API Key** and **Secret API Key** can be found in (or generated from) your Stripe dashboard, within the **Developers** &rarr; **API Keys** tab. Read more about [Stripe API keys](https://stripe.com/docs/keys).

> [!NOTE]
> To prevent secrets leaking into project config, put them in your `.env` file, then use the special [environment variable syntax](https://craftcms.com/docs/4.x/config/#control-panel-settings) in the gateway settings.

Stripe provides different keys for testing—use those until you are ready to launch, then replace the testing keys in the live server’s `.env` file.

### Webhooks

Once the gateway has been saved (and it has an ID), revisiting its edit screen will reveal a **Webhook URL** that can be copied into a [new webhook](https://stripe.com/docs/webhooks#webhooks-summary) in your Stripe dashboard. A signing secret will be generated for you—save this in your `.env` file with the other secrets, then return to the gateway’s settings screen and populate the **Webhook Signing Secret** field with the variable’s name.

> [!WARNING]
> Webhooks will not be processed if the signing secret is missing or invalid!

We recommend enabling _all_ available events for the webhook, in Stripe. Events that the plugin has no use for will be ignored.

Remember that the webhook URL will be different for each of your environments! The gateway itself may have a different ID in production than in development, due to [the way Project Config works](https://craftcms.com/docs/4.x/project-config.html#ids-uuids-and-handles)). Run one of these Commerce console commands to view your gateway’s configuration:

```bash
# Outputs a table with information about each gateway:
php craft commerce/gateways/list

# Outputs a single gateway’s webhook URL:
php craft commerce/gateways/webhook-url myGatewayHandle
```

#### Local Development

Your local environment isn’t often exposed to the public internet, so Stripe won’t be able to send webhooks for testing. You have two options for testing webhooks:

1. Use the [Stripe CLI](https://stripe.com/docs/stripe-cli), replacing the URL in the command below with the one from your gateway’s setting screen:

    ```bash
    stripe listen --forward-to "my-project.ddev.site/index.php?action=commerce/webhooks/process-webhook&gateway=1"
    ```

    This command will create a temporary webhook and signing secret, which you should add to your `.env` file and  See Stripe’s [Testing Webhooks](https://stripe.com/docs/webhooks#test-webhook) article for more information.

2. Use DDEV’s `share` command, and use the public Ngrok URL when configuring the webhook manually, in Stripe.

## Uprading from 3.x

Version 4.0 is largely backward-compatible with 3.x. Review the following sections to ensure your site (and any customizations) remain functional.

### Payment Forms

To support the full array of payment methods available via Stripe (like Apple Pay and Google Pay), the plugin makes exclusive use of the Payment Intents API.

Historically, `gateway.getPaymentFormHtml()` has output a basic form for tokenizing a credit card, client-side—it would then submit only the resulting token to the `commerce/payments/pay` action, and capture the payment from the back-end. **Custom payment forms that use this process will continue to work.**

Now, output is a more flexible [Payment Element](https://stripe.com/docs/payments/payment-element) form, which makes use of Stripes modern [Payment Intents](https://stripe.com/docs/api/payment_intents) API. The process looks something like this:

1. A request is submitted in the background to `commerce/payments/pay` (without a payment method);
1. Commerce creates a Payment Intent with some information about the order, then sets up an internal `Transaction` record to track its status;
1. The Payment Intent’s [`client_secret`](https://stripe.com/docs/api/payment_intents/object#payment_intent_object-client_secret) is returned to the front-end;
1. The Stripe JS SDK is initialized with the secret, and the customer is able to select from the available payment methods;

Details on how to configure the new [payment form](#creating-a-stripe-payment-form) are below.

### API Version

Your Stripe account must be configured to use at least [version `2022-11-15`](https://stripe.com/docs/upgrades) of their API, due to the availability of certain Payment Intents features.

## Configuration Settings

These options are set via `config/commerce-stripe.php`.

### `chargeInvoicesImmediately`

For subscriptions with automatic payments, Stripe creates an invoice 1-2 hours before attempting to charge it. By setting this to `true`, you can force Stripe to charge this invoice immediately.

> [!WARNING]
> This setting affects **all** Stripe gateways in your Commerce installation.

## Subscriptions

### Creating a Subscription Plan

1. Every subscription plan must first be [created in the Stripe dashboard](https://dashboard.stripe.com/test/subscriptions/products).
1. In the Craft control panel, navigate to **Commerce** → **Settings** → **Subscription plans** and create a new subscription plan.

### Subscription Options

In addition to the values you POST to Commerce’s `commerce/subscriptions/subscribe` action, the Stripe gateway supports these options.

#### `trialDays`

The first full billing cycle will start once the number of trial days lapse. Default value is `0`.

### Cancellation Options

#### `cancelImmediately`

If this parameter is set to `true`, the subscription is canceled immediately. Stripe considers this a simultaneous cancellation and “deletion” (as far as webhooks are concerned)—but a record of the subscription remains available. By default, the subscription is marked as canceled and will end along with the current billing cycle. Defaults to `false`.

> [!NOTE]
> Immediately canceling a subscription means it cannot be reactivated.

### Plan-Switching Options

#### `prorate`

If this parameter is set to `true`, the subscription switch will be [prorated](https://stripe.com/docs/billing/subscriptions/upgrade-downgrade#proration). Defaults to `false`.

#### `billImmediately`

If this parameter is set to `true`, the subscription switch is billed immediately. Otherwise, the cost (or credit,
if `prorate` is set to `true` and switching to a cheaper plan) is applied to the next invoice.

> [!WARNING]
> If the billing periods differ, the plan switch will be billed immediately and this parameter will be ignored.

### Reactivation Options

There are no customizations available when reactivating a subscription.

## Events

The plugin provides several events you can use to modify the behavior of your integration.

### Payment Events

#### `buildGatewayRequest`

Plugins get a chance to provide additional metadata when communicating with Stripe in the course of creating a PaymentIntent.

This gives you near-complete control over the data that Stripe sees, with the following considerations:

- Changes to the `Transaction` model (available via the event’s `transaction` property) will not be saved;
- The gateway automatically sets `order_id`, `order_number`, `order_short_number`, `transaction_id`, `transaction_reference`, `description`, and `client_ip` [metadata](https://stripe.com/docs/payments/payment-intents#storing-information-in-metadata) keys;
- Changes to the `amount` and `currency` keys under the `request` property will be ignored, as these are essential to the gateway functioning in a predictable way;

```php
use craft\commerce\models\Transaction;
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\gateways\PaymentIntents;
use yii\base\Event;

Event::on(
    PaymentIntents::class,
    PaymentIntents::EVENT_BUILD_GATEWAY_REQUEST,
    function(BuildGatewayRequestEvent $e) {
        /** @var Transaction $transaction */
        $transaction = $e->transaction;
        $order = $transaction->getOrder();

        $e->request['metadata']['shipping_method'] = $order->shippingMethodHandle;
    }
);
```

> [!NOTE]
> [Subscription events](#subscription-events) are handled separately.

#### `receiveWebhook`

In addition to the generic [`craft\commerce\services\Webhooks::EVENT_BEFORE_PROCESS_WEBHOOK` event](https://docs.craftcms.com/commerce/api/v4/craft-commerce-services-webhooks.html#event-before-process-webhook), you can listen to `craft\commerce\stripe\gateways\PaymentIntents::EVENT_RECEIVE_WEBHOOK`. This event is only emitted after validating a webhook’s authenticity—but it doesn’t make any indication about whether an action was taken in response to it.

```php
use craft\commerce\stripe\events\ReceiveWebhookEvent;
use craft\commerce\stripe\gateways\PaymentIntents;
use yii\base\Event;

Event::on(
    PaymentIntents::class,
    PaymentIntents::EVENT_RECEIVE_WEBHOOK,
    function(ReceiveWebhookEvent $e) {
        if ($e->webhookData['type'] == 'charge.dispute.created') {
            if ($e->webhookData['data']['object']['amount'] > 1000000) {
                // Be concerned that a USD 10,000 charge is being disputed.
            }
        }
    }
);
```

`webhookData` will always have a `type` key, which determines the schema of everything within `data`. Check the Stripe documentation for what kinds of data to expect.

### Subscription Events

#### `createInvoice`

Plugins get a chance to do something when an invoice is created on the Stripe gateway.

```php
use craft\commerce\stripe\events\CreateInvoiceEvent;
use craft\commerce\stripe\gateways\PaymentIntents;
use yii\base\Event;

Event::on(
    PaymentIntents::class, 
    PaymentIntents::EVENT_CREATE_INVOICE,
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
use craft\commerce\stripe\gateways\PaymentIntents;
use yii\base\Event;

Event::on(
    PaymentIntents::class,
    PaymentIntents::EVENT_BEFORE_SUBSCRIBE,
    function(SubscriptionRequestEvent $e) {
        /** @var craft\commerce\base\Plan $plan */
        $plan = $e->plan;

        /** @var craft\elements\User $user */
        $user = $e->user;

        // Add something to the metadata:
        $e->parameters['metadata']['name'] = $user->fullName;
        unset($e->parameters['metadata']['another_property']);
    }
);
```

## Billing Portal

You can now generate a link to the [Stripe billing portal](https://stripe.com/docs/customer-management) for customers to manage their credit cards and plans.

```twig
<a href={{ gateway.billingPortalUrl(currentUser) }}">Manage your billing account</a>
```

Pass a `returnUrl` parameter to return the customer to a specific on-site page after they have finished:

```twig
{{ gateway.billingPortalUrl(currentUser, 'myaccount') }}
```

A specific Stripe customer portal configuration can be chosen with the third `configurationId` argument. This value must agree with a preexisting configuration [created via the API](https://stripe.com/docs/customer-management/integrate-customer-portal#customize) in the associated Stripe account:

```twig
{{ gateway.billingPortalUrl(currentUser, 'myaccount', 'config_12345') }}
```

> [!NOTE]
> Logged-in users can also be redirected with the `commerce-stripe/customers/billing-portal-redirect` action. The `configurationId` parameter is not supported when using this method.

## Syncing Customer Payment Methods

Payment methods created directly in the Stripe customer portal are now synchronized back to Commerce. Webhooks must be configured for this to work as expected!

To perform an initial sync, run the `commerce-stripe/sync/payment-sources` console command:

```bash
php craft commerce-stripe/sync/payment-sources
```

## Creating a Stripe Payment Form

To render a Stripe Elements payment form, get a reference to the gateway, then call its `getPaymentFormHtml()` method:

```twig
{% set cart = craft.commerce.carts.cart %}
{% set gateway = cart.gateway %}

<form method="POST">
  {{ csrfInput() }}
  {{ actionInput('commerce/payments/pay') }}

  {% namespace gateway.handle|commercePaymentFormNamespace %}
    {{ gateway.getPaymentFormHtml({})|raw }}
  {% endnamespace %}

  <button>Pay</button>
</form>
```

This assumes you have provided a means of selecting the gateway in a prior checkout step. If your store only uses a single gateway, you may get a reference to the gateway statically and set it during payment:

```twig
{% set gateway = craft.commerce.gateways.getGatewayByHandle('myStripeGateway') %}

<form method="POST">
  {{ csrfInput() }}
  {{ actionInput('commerce/payments/pay') }}

  {# Include *outside* the namespaced form inputs: #}
  {{ hiddenInput('gatewayId', gateway.id) }}

  {% namespace gateway.handle|commercePaymentFormNamespace %}
    {{ gateway.getPaymentFormHtml({})|raw }}
  {% endnamespace %}

  <button>Pay</button>
</form>
```

Regardless of how you use this output, it will automatically register all the necessary Javascript to create the Payment Intent and bootstrap Stripe Elements.

### Payment Form Contexts

Commerce uses payment forms in three places:

- Paying for a cart (`commerce/payments/pay`);
- Adding a saved payment source (`commerce/payment-sources/add`);
- Starting a subscription (`commerce/subscriptions/subscribe`);

> [!NOTE]
> When creating a subscription, you should only include the payment form HTML if the customer does not already have a saved payment source. Subscriptions are always created with the customer’s primary payment source.



## Customizing the Stripe Payment Form

`getPaymentFormHtml()` accepts one argument, an array with any of the following keys:

### `paymentFormType`

#### `elements`

Renders a Stripe Elements form with all the payment method types [enabled in your Stripe Dashboard](https://stripe.com/docs/payments/customize-payment-methods). Some methods may be hidden if the order total or currency don’t meet the method’s criteria—or if they aren’t supported in the current environment.

```twig
{% set params = {
  paymentFormType: 'elements',
} %}

{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```

This is the default `paymentFormType`, and most installations will not need to alter it.

#### `checkout`

This generates a form ready to redirect to Stripe Checkout. This can only be used inside a `commerce/payments/pay` form. This option ignores all other params.

```twig
{% set params = {
  paymentFormType: 'checkout',
} %}

{{ gateway.getPaymentFormHtml(params)|raw }}
```

### `appearance`

You can pass an array of appearance options to the `stripe.elements()` configurator function. This expects data compatible with the [Elements Appearance API](https://stripe.com/docs/elements/appearance-api).

```twig
{% set params = {
  appearance: {
    theme: 'stripe'
  }
} %}
{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```

```twig
{% set params = {
  appearance: {
    theme: 'night',
    variables: {
      colorPrimary: '#0570de'
    }
  }
} %}
{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```

### `elementOptions`

Modify the [Payment Element options](https://stripe.com/docs/js/elements_object/create_payment_element#payment_element_create-options) passed to the `elements.create()` factory function.

```twig
{% set params = {
  elementOptions: {
    layout: {
      type: 'tabs',
      defaultCollapsed: false,
      radios: false,
      spacedAccordionItems: false
    }
  }
} %}
{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```

The default `elementOptions` value only defines a layout:

```twig
{% set params = {
  elementOptions: {
    layout: {
      type: 'tabs'
    }
  }
} %}
```

### `errorMessageClasses`

Error messages are displayed in a container above the form. You can add classes to this element to alter its style.

```
{% set params = {
  errorMessageClasses: 'bg-red-200 text-red-600 my-2 p-2 rounded',
} %}

{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```

### `submitButtonClasses` and `submitButtonText`

Customize the style and text of the button used to submit a payment form.

```twig
{% set params = {
  submitButtonClasses: 'cursor-pointer rounded px-4 py-2 inline-block bg-blue-500 hover:bg-blue-600 text-white hover:text-white my-2',
  submitButtonText: 'Pay',
} %}

{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```
