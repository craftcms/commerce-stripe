Stripe payment gateway for Craft Commerce
=======================

This plugin provides a [Stripe](https://stripe.com/) integration for [Craft Commerce](https://craftcommerce.com/).


## Requirements

This plugin requires Craft Commerce 2.0.1-alpha.4 or later.


## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require craftcms/commerce-stripe

3. In the Control Panel, go to Settings → Plugins and click the “Install” button for Stripe.

## Setup

To add the Stripe payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “Stripe”.

## Payment security enforcement

This plugin does not allow submitting credit card details directly to Stripe gateway. Instead, you must tokenize the card before submitting the payment form. See [here](src/resources/js/paymentForm.js) for an example on how it's done when calling the default `getPaymentFormHtml()` method on the gateway.

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