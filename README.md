<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Stripe for Craft Commerce icon"></p>

<h1 align="center">Stripe for Craft Commerce</h1>

This plugin provides a [Stripe](https://stripe.com/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

This plugin requires Craft Commerce 2.0.0-alpha.5 or later.

This plugin uses Stripe API version '2018-11-08'.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Stripe for Craft Commerce”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftcms/commerce-stripe

# tell Craft to install the plugin
./craft install/plugin commerce-stripe
```

## Setup

To add the Stripe payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “Stripe”.

## Payment security enforcement

This plugin does not allow submitting credit card details directly to Stripe gateway. Instead, you must tokenize the card before submitting the payment form. See [here](src/web/assets/paymentform/js/paymentForm.js) for an example on how it's done when calling the default `getPaymentFormHtml()` method on the gateway.

## 3D secure payments

To allow 3D Secure payments, you must perform some additional steps.

### Forcing a 3D secure payment.

For some cards 3D secure payments are not supported, for some they are mandatory while for some cards they are optional. Setting this setting to true for a gateway will force the 3D secure payment flow for cards which optionally support it.

Cards that do not support 3d secure payment will be unaffected by this setting.

## Webhooks

### Configure Stripe

Set up a webhook endpoint in your Stripe dashboard API settings. The URL for this endpoint can be found in your Commerce Stripe gateway settings.

It is recommended to emit all possible events, but the required events are:

#### For 3D secure payments

 * `source.cancelled`
 * `source.chargeable`
 * `source.failed`

#### For subscriptions

The bare minimum of events required are:

* `invoice.payment_succeeded`
* `customer.subscription.deleted`

However, it is strongly recommended to enable the following events as well to ensure your Commerce subscriptions stay in sync with your Stripe dashboard:

* `plan.deleted`
* `plan.updated`
* `invoice.created`
* `customer.subscription.updated`

### Configure the gateway

When the endpoint has been set up, you can view the signing secret in its settings. Enter this value in your Stripe gateway settings in the appropriate field.

### Disabling CSRF for webhooks.

You must disable CSRF protection for the incoming requests, assuming it is enabled for the site (default for Craft since 3.0).

A clean example for how to go about this can be found [here](https://craftcms.stackexchange.com/a/20301/258).

## Configuration settings

### The `chargeInvoicesImmediately` setting

For subscriptions with automatic payments, Stripe creates an invoice 1-2 hours before attempting to charge it. By setting this to true in your `commerce-stripe.php` config file, you can force Stripe to charge this invoice immediately.

This setting affect all Stripe gateways on your Commerce installation.

## Subscriptions

### Creating a subscription plan

1. To create a subscription plan, it must first be [created on Stripe](https://dashboard.stripe.com/test/subscriptions/products).
2. Go to Commerce → Settings → Subscription plans and create a new subscription plan.

### Options when subscribing

#### The `trialDays` parameter

When subscribing, you can pass a `trialDays` parameter. The first full billing cycle will start once the number of trial days lapse. Default value is `0`.

### Options when cancelling a subscription.

#### The `cancelImmediately` parameter

If this parameter is set to `true`, the subscription is canceled immediately. Otherwise, it is marked to cancel at the end of the current billing cycle. Defaults to `false`.

### Options when switching between different subscription plans

#### The `prorate` parameter

If this parameter is set to true, the subscription switch will be [prorated](https://stripe.com/docs/subscriptions/upgrading-downgrading#understanding-proration). Defaults to `false`.

#### The `billImmediately` parameter

If this parameter is set to true, the subscription switch is billed immediately. Otherwise, the cost (or credit, if `prorate` is set to true and switching to a cheaper plan) is applied to the next invoice.

Please note, that the subscription switch will be billed immediately regardless of this parameter if the billing periods differ.
