<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Stripe for Craft Commerce icon"></p>

<h1 align="center">Stripe for Craft Commerce</h1>

This plugin provides a [Stripe](https://stripe.com/) integration for [Craft Commerce](https://craftcms.com/commerce), with support for [Payment Intents](https://stripe.com/docs/payments/payment-intents) as well as traditional charges.

## Requirements

This plugin requires Craft 3.1.5 and Craft Commerce 2.1.4 or later.

This plugin uses Stripe API version '2019-03-14'.

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

## Changes in 2.0

The old “Stripe” gateway has been renamed to “Stripe Charge”, and is now deprecated. A new “Stripe Payment Intents” gateway has been added which uses Stripe’s new [Payment Intents API](https://stripe.com/docs/payments/payment-intents), and utilizes [3D Secure 2](https://stripe.com/guides/3d-secure-2), which is easier to implement than the old 3D Secure standard and delivers a better customer experience. **Stripe will begin declining all EU charges using its old charge API on September 14, 2019,** so switching to the new Payment Intents gateway is highly recommended. (See Stripe’s [Strong Customer Authentication](https://stripe.com/guides/strong-customer-authentication) guide for more info.)

## Setup

To add the Stripe payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “Stripe Payment Intents”.
 
 > Note: A “Stripe Charge” gateway is also available, but it is deprecated. (See [Changes in 2.0](#changes-in-2-0).)

## Payment security enforcement

This plugin does not allow submitting credit card details directly. Instead, you must create a payment method before submitting the payment form. See [here](src/web/assets/intentsform/js/paymentForm.js) for an example on how it's done when calling the default `getPaymentFormHtml()` method on the gateway.

## Webhooks

### Configure Stripe

Set up a webhook endpoint in your Stripe dashboard API settings. The URL for this endpoint can be found in your Commerce Stripe gateway settings.

It is recommended to emit all possible events, but the required events are:

#### For 3D secure payments (if using Stripe Charge gateway)

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

## Events
The plugin provides several events that allow you to modify the behaviour of your integration.


### Payment request related events

#### The `buildGatewayRequest` event
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

Event::on(StripeGateway::class, StripeGateway::EVENT_BUILD_GATEWAY_REQUEST, function(BuildGatewayRequestEvent $e) {
  if ($e->transaction->type === 'refund') {
    $e->request['someKey] = 'some value';
  }
});
```

#### The `receiveWebhook` event
Plugins get a chance to do something whenever a webhook is received. This event will be fired regardless the Gateway has done something with the webhook or not.

```php
use craft\commerce\stripe\events\ReceiveWebhookEvent;
use craft\commerce\stripe\base\Gateway as StripeGateway;
use yii\base\Event;

Event::on(StripeGateway::class, StripeGateway::EVENT_RECEIVE_WEBHOOK, function(ReceiveWebhookEvent $e) {
  if ($e->webhookData['type'] == 'charge.dispute.created') {
    if ($e->webhookData['data']['object']['amount'] > 1000000) {
      // Be concerned that a USD 10,000 charge is being disputed.
    }
  }
});
```

### Subscription events

#### The `createInvoice` event
Plugins get a chance to do something when an invoice is created on the Stripe gateway.
```php
use craft\commerce\stripe\events\CreateInvoiceEvent;
use craft\commerce\stripe\base\SubscriptionGateway as StripeGateway;
use yii\base\Event;

Event::on(StripeGateway::class, StripeGateway::EVENT_CREATE_INVOICE, function(CreateInvoiceEvent $e) {
    if ($e->invoiceData['billing'] === 'send_invoice') {
        // Forward this invoice to the accounting dpt.
    }
});
```

#### The `beforeSubscribe` event
Plugins get a chance to tweak subscription parameters when subscribing.

```php
use craft\commerce\stripe\events\SubscriptionRequestEvent;
use craft\commerce\stripe\base\SubscriptionGateway as StripeGateway;
use yii\base\Event;

Event::on(StripeGateway::class, StripeGateway::EVENT_BEFORE_SUBSCRIBE, function(SubscriptionRequestEvent $e) {
    $e->parameters['someKey'] = 'some value';
    unset($e->parameters['unneededKey']);
});
```

### Deprecated events
The following events are deprecated as they are associated with the deprecated Stripe Charge gateway.

#### The `receive3dsPayment` event
Plugins get a chance to do something whenever a successful 3D Secure payment is received.

```php
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\events\Receive3dsPaymentEvent;
use craft\commerce\stripe\gateways\Gateway as StripeGateway;
use yii\base\Event;

Event::on(StripeGateway::class, StripeGateway::EVENT_RECEIVE_3DS_PAYMENT, function(Receive3dsPaymentEvent $e) {
    $order = $e->transaction->getOrder();
    $orderStatus = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle('paid');
    if ($order && $paidStatus && $order->orderStatusId !== $paidStatus->id && $order->getIsPaid()) {
        $order->orderStatusId = $paidStatus->id;
        Craft::$app->getElements()->saveElement($order);
    }
});
```
