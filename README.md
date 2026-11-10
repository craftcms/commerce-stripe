<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="Stripe for Craft Commerce icon"></p>

<h1 align="center">Stripe for Craft Commerce</h1>

Flexible payment processing for your [Craft Commerce](https://craftcms.com/commerce) store, powered by [Stripe](https://stripe.com/).

This plugin provides a [gateway](https://craftcms.com/docs/commerce/6.x/system/gateways.html) that leverages the [Payment Intents](https://stripe.com/docs/payments/payment-intents) API to support popular payment methods like…

- Major debit and credit cards
- Apple Pay
- Google Pay
- Cash App
- Afterpay, Affirm, and other installment plans
- ECH and direct bank account transfers

…and [more](https://stripe.com/guides/payment-methods-guide)!

> [!NOTE]
> Looking for the Craft Commerce 5 version? See the [6.x branch](https://github.com/craftcms/commerce-stripe/tree/6.x). If you’re upgrading from it, see [UPGRADE.md](UPGRADE.md).

> [!WARNING]
> Craft Commerce 6 doesn’t support subscriptions, so this version of the plugin doesn’t either. Use the [Stripe plugin for Craft CMS](https://plugins.craftcms.com/stripe) for subscriptions, and move your existing subscriptions to it _before_ upgrading. See [Moving Subscriptions to the Stripe Plugin](#moving-subscriptions-to-the-stripe-plugin).

## Requirements

- Craft CMS 6.0 or later
- Craft Commerce 6.0 or later
- PHP 8.5 or later
- Stripe [API version](https://stripe.com/docs/api/versioning) `2026-01-28.clover`

> [!IMPORTANT]
> If you have custom code that interacts with Stripe API data (e.g. event listeners, custom webhook handlers, or code that reads stored Stripe response data), review the [Stripe API changelog](https://docs.stripe.com/changelog) for breaking changes between your previous API version and `2026-01-28.clover`.

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
php artisan craft:plugin:install commerce-stripe
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
> To prevent secrets leaking into project config, put them in your `.env` file, then use the special [environment variable syntax](https://craftcms.com/docs/6.x/configure.html#control-panel-settings) in the gateway settings.

Stripe provides different keys for testing—use those until you are ready to launch, then replace the testing keys in the live server’s `.env` file.

#### Restricted API Keys

Using a [restricted API key](https://stripe.com/docs/keys#limit-access) instead of a standard secret key is optional, but improves security by limiting what the key can do if it is ever compromised. If you choose to use a restricted key, grant **Write** access (which includes read) to the following resources:

| Resource | Access |
|---|---|
| Payment Intents | Write |
| Payment Methods | Write |
| Setup Intents | Write |
| Customers | Write |
| Checkout Sessions | Write |
| Refunds | Write |
| Billing Portal | Write |

> [!NOTE]
> The **Webhook Signing Secret** is separate from the API key and is obtained when you register your webhook endpoint in Stripe—see [Webhooks](#webhooks) below.

> [!WARNING]
> If you use a restricted key, any third-party plugins or custom modules that interact with the Stripe API may require additional permissions beyond those listed above. Review their documentation and add permissions accordingly.
> 
> Whenever this plugin begins using a new API resource or permission, it will be noted in the [changelog](./CHANGELOG.md).

### Webhooks

Once the gateway has been saved (and it has an ID), revisiting its edit screen will reveal a **Webhook URL** that can be copied into a [new webhook](https://stripe.com/docs/webhooks#webhooks-summary) in your Stripe dashboard. A signing secret will be generated for you—save this in your `.env` file with the other secrets, then return to the gateway’s settings screen and populate the **Webhook Signing Secret** field with the variable’s name.

> [!WARNING]
> Webhooks will not be processed if the signing secret is missing or invalid!

Rather than enabling all available events, we recommend selecting only the specific events the plugin handles. Selecting all events causes Stripe to warn about inefficiency, and may result in Stripe splitting your endpoint into two separate endpoints with different signing secrets—one for standard (snapshot) payloads and one for [thin payloads](https://docs.stripe.com/event-destinations)—which is incompatible with the plugin's single signing secret field.

The plugin handles the following webhook events:

| Event |
|---|
| `customer.updated` |
| `customer_cash_balance_transaction.created` |
| `payment_intent.requires_action` |
| `payment_intent.succeeded` |
| `payment_method.attached` |
| `payment_method.automatically_updated` |
| `payment_method.detached` |
| `payment_method.updated` |
| `refund.updated` |

> [!WARNING]
> Restricting webhook events to this list is optional, but improves efficiency. Any third-party plugins or custom modules that listen to Stripe webhooks via the [`WebhookReceived`](#webhookreceived) event may require additional event types to be enabled in Stripe.
>
> Whenever this plugin begins handling a new webhook event type, it will be noted in the [changelog](./CHANGELOG.md).

Remember that the webhook URL will be different for each of your environments! The gateway itself may have a different ID in production than in development, due to the way project config works.

#### Local Development

Your local environment isn’t often exposed to the public internet, so Stripe won’t be able to send webhooks for testing. You have two options for testing webhooks:

1. Use the [Stripe CLI](https://stripe.com/docs/stripe-cli), replacing the URL in the command below with the one from your gateway’s setting screen:

    ```bash
    stripe listen --forward-to "my-project.test/actions/commerce/webhooks/process-webhook?gateway=1"
    ```

    This command will create a temporary webhook and signing secret, which you should add to your `.env` file. See Stripe’s [Testing Webhooks](https://stripe.com/docs/webhooks#test-webhook) article for more information.

2. Expose your local site with a tunnel (such as ngrok), and use the public URL when configuring the webhook manually, in Stripe.

## Moving Subscriptions to the Stripe Plugin

Craft Commerce 6 doesn’t support subscriptions. We recommend using the [Stripe plugin for Craft CMS](https://plugins.craftcms.com/stripe) (`craftcms/stripe`) for subscriptions. It works directly with Stripe’s products, prices, and subscriptions, and doesn’t require Craft Commerce.

The Stripe plugin can migrate your existing Commerce subscriptions without losing any customer data. Do this while your project is still on Craft Commerce 5, before you upgrade. Once you have [installed and configured it](https://github.com/craftcms/stripe#installation), run its migrate command, then a full sync:

```bash
# Pre-populate the Stripe plugin’s tables with your existing Commerce subscription data:
php craft stripe/commerce/migrate

# Bring in the remaining records from Stripe:
php craft stripe/sync/all
```

Subscriptions behave a little differently in the Stripe plugin, so review its [migration guide](https://github.com/craftcms/stripe#migrating-from-craft-commerce) for the API changes before updating your templates.

The subscriptions themselves live in Stripe, so they keep billing your customers after you upgrade. This plugin keeps its `stripe_invoices` table when you upgrade, but no longer reads or writes it.

## Events

The plugin dispatches several [Laravel events](https://laravel.com/docs/events) you can use to modify the behavior of your integration. Listen for them with `Illuminate\Support\Facades\Event::listen()`, e.g. from a module’s service provider.

### `GatewayRequestBuilding`

Plugins get a chance to provide additional metadata when communicating with Stripe in the course of creating a Payment Intent or Checkout Session. This gives you near-complete control over the data that Stripe sees, with the following considerations:

- Changes to the `Transaction` (available via the event’s `transaction` property) will not be saved;
- The gateway automatically sets `order_id`, `order_number`, `order_short_number`, `transaction_id`, `transaction_reference`, `description`, and `client_ip` [metadata](https://stripe.com/docs/payments/payment-intents#storing-information-in-metadata) keys;
- Changes to the `amount` and `currency` keys under the `request` property will be ignored, as these are essential to the gateway functioning in a predictable way;
- The event’s `type` property is `payment_intent` or `checkout.session`.

```php
use CraftCms\CommerceStripe\Events\GatewayRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(GatewayRequestBuilding $event): void {
    $order = $event->transaction->getOrder();

    $event->request['metadata']['shipping_method'] = $order->shippingMethodHandle;
});
```

### `PaymentFormRendering`

Modify the params passed to `getPaymentFormHtml()` before the payment form is rendered. The event’s `params` property has already been merged with the defaults, and `order` is the order passed with the [`order`](#order-optional) param, if any.

This is useful for setting payment form options for every template, like filling in a billing phone number from a custom address field when using [`serverSideConfirmation`](#serversideconfirmation):

```php
use CraftCms\CommerceStripe\Events\PaymentFormRendering;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentFormRendering $event): void {
    $event->params['serverSideConfirmation'] = true;
    // Assumes addresses have a custom `phone` field
    $event->params['billingDetails']['phone'] = $event->order?->getBillingAddress()?->getFieldValue('phone');
});
```

### `ServerSideConfirming`

Dispatched when the payment form uses [`serverSideConfirmation`](#serversideconfirmation), before the Payment Intent is created and confirmed. The event’s `confirmationToken` property is the [ConfirmationToken](https://docs.stripe.com/api/confirmation_tokens/object) created by Stripe.js, and its `payment_method_preview` describes the customer’s payment method (type, card brand, funding, country, and so on).

Set `isValid` to `false` to reject the payment before it is confirmed. The `errorMessage` is shown to the customer:

```php
use CraftCms\CommerceStripe\Events\ServerSideConfirming;
use Illuminate\Support\Facades\Event;

Event::listen(function(ServerSideConfirming $event): void {
    if ($event->confirmationToken->payment_method_preview->card?->funding === 'prepaid') {
        $event->isValid = false;
        $event->errorMessage = 'Prepaid cards are not accepted.';
    }
});
```

Retrieving the ConfirmationToken requires an extra request to Stripe, which is only made when a listener is registered.

### `PaymentIntentConfirming`

Modify the parameters used to confirm a Payment Intent, like the `return_url`. The event’s `transaction` property is the transaction being paid. Changes to `amount` and `currency` are ignored.

```php
use CraftCms\CommerceStripe\Events\PaymentIntentConfirming;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentIntentConfirming $event): void {
    $event->parameters['receipt_email'] = $event->transaction->getOrder()->email;
});
```

### `SetupIntentRequestBuilding`

Modify the request used to create a [Setup Intent](https://docs.stripe.com/api/setup_intents/create) when a customer saves a payment method:

```php
use CraftCms\CommerceStripe\Events\SetupIntentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(SetupIntentRequestBuilding $event): void {
    $event->request['payment_method_types'] = ['card'];
});
```

### `CustomerCreating`

Modify the request used to [create a Stripe customer](https://docs.stripe.com/api/customers/create) for a Craft user:

```php
use CraftCms\CommerceStripe\Events\CustomerCreating;
use Illuminate\Support\Facades\Event;

Event::listen(function(CustomerCreating $event): void {
    $event->customer['metadata']['username'] = $event->user->username;
});
```

### `WebhookReceived`

In addition to Commerce’s generic webhook events, you can listen for `CraftCms\CommerceStripe\Events\WebhookReceived`. This event is only dispatched after validating a webhook’s authenticity—but it doesn’t make any indication about whether an action was taken in response to it.

```php
use CraftCms\CommerceStripe\Events\WebhookReceived;
use Illuminate\Support\Facades\Event;

Event::listen(function(WebhookReceived $event): void {
    if ($event->webhookData['type'] === 'charge.dispute.created') {
        if ($event->webhookData['data']['object']['amount'] > 1000000) {
            // Be concerned that a USD 10,000 charge is being disputed.
        }
    }
});
```

`webhookData` will always have a `type` key, which determines the schema of everything within `data`. Check the Stripe documentation for what kinds of data to expect.

## Billing Portal

You can generate a link to the [Stripe billing portal](https://stripe.com/docs/customer-management) for customers to manage their payment methods.

```twig
<a href="{{ gateway.billingPortalUrl(currentUser) }}">Manage your billing account</a>
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
> Logged-in users can also be redirected with the `commerce-stripe/customers/billing-portal-redirect` action. Post the gateway’s handle as `gatewayId`, and an optional hashed `redirect` param. The `configurationId` parameter is not supported when using this method.

## Syncing Customer Payment Methods

Payment methods created directly in the Stripe customer portal are synchronized back to Commerce. Customers’ primary payment methods are also synchronized.

> [!NOTE]
> Webhooks must be configured for this to work as expected!

To perform an initial sync, run the `commerce-stripe:sync:payment-sources` command:

```bash
php artisan commerce-stripe:sync:payment-sources
```

It asks which Stripe gateway to sync, then creates or updates a payment source for every payment method of every Stripe customer that belongs to a Craft user.

Admins can also run the sync for a gateway by posting its `gatewayId` to the `commerce-stripe/default/sync-payment-sources` action.

## Resetting Plugin Data

To delete all the plugin’s Stripe customer, payment intent and invoice records (for example, after copying a production database to a development environment that uses Stripe’s test keys), run:

```bash
php artisan commerce-stripe:reset-data
```

Pass `--force` to skip the confirmation prompt.

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

Regardless of how you use this output, it will automatically register all the necessary JavaScript to create the Payment Intent and bootstrap Stripe Elements.

The gateway builds the form with Commerce’s `paymentFormUi()` method, which `getPaymentFormHtml()` renders. To change the markup, extend the gateway and override `paymentFormUi()`, or render your own form.

## Customizing the Stripe Payment Form

`getPaymentFormHtml()` accepts an array with any of the following keys:

### `paymentFormType`

#### `elements` (default)

Renders a Stripe Elements form with all the payment method types [enabled in your Stripe Dashboard](https://stripe.com/docs/payments/customize-payment-methods). Some methods may be hidden if the order total or currency don’t meet the method’s criteria—or if they aren’t supported in the current environment.

```twig
{% set params = {
  paymentFormType: 'elements',
} %}

{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```

This is the default `paymentFormType`, and most installations will not need to declare or change it.

#### `checkout`

This generates a form ready to redirect to a hosted [Stripe Checkout](https://stripe.com/docs/payments/checkout) page. This can only be used inside a `commerce/payments/pay` form. This option ignores all other params.

```twig
{% set params = {
  paymentFormType: 'checkout',
} %}

{{ gateway.getPaymentFormHtml(params)|raw }}
```

### `serverSideConfirmation`

By default, the payment form creates a Payment Intent as soon as the page loads, and Stripe.js confirms it in the browser. Set `serverSideConfirmation` to `true` to instead [finalize the payment on the server](https://docs.stripe.com/payments/finalize-payments-on-the-server):

```twig
{% set params = {
  order: cart,
  serverSideConfirmation: true,
} %}

{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```

With this option enabled:

- Nothing is sent to `commerce/payments/pay` until the customer submits the form. Stripe Elements is created without a Payment Intent, and Stripe.js creates a [ConfirmationToken](https://docs.stripe.com/api/confirmation_tokens) from the customer’s payment details.
- The form is then submitted to `commerce/payments/pay`, which runs Commerce’s usual order and payment amount checks, then creates and confirms the Payment Intent on the server for the current outstanding balance.
- If the payment needs further action (like 3D Secure, or redirecting to Klarna), Stripe.js handles it. The customer is then sent to `commerce/payments/complete-payment`, just like the default flow.

Use the [`ServerSideConfirming`](#serversideconfirming) event to inspect the customer’s payment method and reject the payment before it is confirmed.

> [!NOTE]
> This param only changes how the payment form behaves. The gateway confirms a payment on the server whenever a ConfirmationToken is submitted with the payment form, whether or not the template sets `serverSideConfirmation`. Commerce’s order and payment amount checks apply either way.

The order’s billing details can be sent to Stripe instead of being collected by the Payment Element. Tell the Payment Element not to collect them with `elementOptions`, and the plugin will provide them from the order’s billing address:

```twig
{% set params = {
  order: cart,
  serverSideConfirmation: true,
  elementOptions: {
    fields: {
      billingDetails: 'never',
    },
  },
} %}
```

You can also set individual fields to `'never'` (e.g. `billingDetails: { address: 'never' }`). Use the `billingDetails` param to override any of the values taken from the order.

The amount and currency shown by Stripe Elements are taken from the order when the form is rendered, and are only used to decide which payment methods to show. The amount actually charged is calculated on the server. Additional [Elements options](https://docs.stripe.com/js/elements_object/create_without_intent) can be passed with the `elementsOptions` param.

> [!NOTE]
> If you modify the Payment Intent with the [`GatewayRequestBuilding`](#gatewayrequestbuilding) event (e.g. `payment_method_types`, `capture_method`, or `setup_future_usage`), make sure the matching `elementsOptions` are passed to the payment form, as Stripe requires them to match.

### `elementsOptions`

Only used with [`serverSideConfirmation`](#serversideconfirmation). Additional [Elements options](https://docs.stripe.com/js/elements_object/create_without_intent) for creating Stripe Elements without a Payment Intent. These are merged over the defaults the plugin sets from the order and gateway (`mode`, `amount`, `currency`, `captureMethod`, and `paymentMethodCreation`):

```twig
{% set params = {
  order: cart,
  serverSideConfirmation: true,
  elementsOptions: {
    paymentMethodTypes: ['card', 'klarna'],
  },
} %}
```

### `billingDetails`

Only used with [`serverSideConfirmation`](#serversideconfirmation). Overrides the billing details taken from the order’s billing address, which are sent to Stripe when the Payment Element is set not to collect them (`elementOptions.fields.billingDetails` is `'never'`). Stripe requires every billing detail in that case, so `phone` is sent as `null` unless you provide one:

```twig
{% set params = {
  order: cart,
  serverSideConfirmation: true,
  elementOptions: {
    fields: {
      billingDetails: 'never',
    },
  },
  billingDetails: {
    email: currentUser.email ?? cart.email,
  },
} %}
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

### `order` (optional)

The `order` key should be a reference to a Commerce `Order` model, which would usually be the current `cart` variable in your template.

If supplied, the [billing details](https://stripe.com/docs/js/elements_object/create_payment_element#payment_element_create-options-defaultValues) are added to `elementOptions`’s default `defaultValues` array.

```twig
{% set params = {
  order: cart,
} %}

{{ cart.gateway.getPaymentFormHtml(params)|raw }}
```

If you do not pass an `order` into the payment form, you can opt to manually populate the [billing details](https://stripe.com/docs/js/elements_object/create_payment_element#payment_element_create-options-defaultValues) using the `elementOptions`’s `defaultValues` key:

```twig
{% set params = {
  elementOptions: {
    defaultValues: {
      name: 'Jane Doe',
      address: {
        line1: '123 Main St',
        city: 'Anytown',
        state: 'NY',
        postal_code: '12345',
        country: 'US',
      },
    }
    ...
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
