# Release Notes for Stripe for Craft Commerce

## Unreleased

-Fixed a PHP error that could occur when processing a payment from a console request. ([#370](https://github.com/craftcms/commerce-stripe/issues/370))

## 5.1.0.3 - 2026-02-19

- Fixed a bug where Stripe subscription statuses `trialing` and `unpaid` were not being mapped correctly to Craft Commerce subscription statuses.
- Fixed a PHP error that could occur when `sendReceiptEmail` was set to a missing environment variable. ([#364](https://github.com/craftcms/commerce-stripe/issues/364))

## 5.1.0.2 - 2026-02-03

- Fixed a bug where more than one child transaction could be marked as successful.

## 5.1.0.1 - 2025-07-09

- Fixed a bug where the Stripe Elements were using the browser’s preferred language rather than the current application locale. ([#351](https://github.com/craftcms/commerce-stripe/issues/351))

## 5.1.0 - 2025-07-09

- Added support for fonts in Stripe Elements. ([#258](https://github.com/craftcms/commerce-stripe/issues/258))
- Stripe Element forms now accept a `locale` payment form parameter. ([#351](https://github.com/craftcms/commerce-stripe/issues/351))
- Added `craft\commerce\stripe\db\Table`.
- Fixed a bug where the Stripe API version wasn’t being set on all API requests. ([#340](https://github.com/craftcms/commerce-stripe/issues/340))
- Fixed a bug where the Stripe Elements were using the browser’s preferred language rather than the current application locale.
- Fixed a bug where successful payment intent webhooks weren’t marking the transaction as successfully completed. ([#337](https://github.com/craftcms/commerce-stripe/issues/337), [#207](https://github.com/craftcms/commerce-stripe/issues/207))
- Fixed a bug where some successful refund webhooks weren’t marking transactions as refunded. ([#341](https://github.com/craftcms/commerce-stripe/issues/341))
- Fixed an error that could occur on install, if the plugin had been installed previously. ([#350](https://github.com/craftcms/commerce-stripe/issues/350))

## 5.0.7 - 2025-03-19

- Fixed an error that could occur when attempting a payment while using asynchronous CSRF inputs. ([#334](https://github.com/craftcms/commerce-stripe/issues/334))

## 5.0.6 - 2025-03-06

- Improved the performance of loading plans in the control panel. ([#322](https://github.com/craftcms/commerce-stripe/issues/322))
- Fixed a bug where checkout session completion would not mark transactions as successful from webhooks. ([#318](https://github.com/craftcms/commerce-stripe/issues/318))
- Fixed a bug where choosing bank transfer as a payment method wouldn’t complete an order. ([#315](https://github.com/craftcms/commerce-stripe/issues/315))
- Fixed a PHP error that could occur when handling a webhook request.
- Added `craft\commerce\stripe\SubscriptionGateway::handleCustomerCashBalanceTransaction()`.
- Added `craft\commerce\stripe\SubscriptionGateway::transactionSupportsRefund()`.

## 5.0.5 - 2025-03-02

- Stripe now returns the correct HTTP error codes for invalid webhook requests. ([#314](https://github.com/craftcms/commerce-stripe/issues/314))
- Fixed a PHP error that occurred when receiving webhook events for invoices that did not originate from Craft Commerce. ([#317](https://github.com/craftcms/commerce-stripe/issues/317))

## 5.0.4.4 - 2025-02-03

- Fixed a JavaScript error that could occur when attempting to make a payment on a completed order. ([#312](https://github.com/craftcms/commerce-stripe/issues/312))
- Fixed a PHP error that could occur when syncing remote payment methods for customers that don't exist in Craft Commerce. ([#316](https://github.com/craftcms/commerce-stripe/issues/316))

## 5.0.4.3 - 2024-09-17

- Fixed a bug where free orders that completed immediately were not redirecting to the order success page.

## 5.0.4.2 - 2024-08-12

- Fixed a PHP error that occurred when receiving webhook events for subscriptions that did not originate from Craft Commerce. ([#309](https://github.com/craftcms/commerce-stripe/pull/309))

## 5.0.4.1 - 2024-08-09
 
- Fixed a bug where webhooks weren’t being handled correctly when an associated transaction was found. ([#308](https://github.com/craftcms/commerce-stripe/pull/308))

## 5.0.4 - 2024-08-08

- Fixed a PHP error that occurred when receiving webhook events for payments that did not originate from Craft Commerce.

## 5.0.3 - 2024-08-08

- Fixed a PHP error that occurred when receiving webhook events for plans that are not configured in Craft Commerce.
 
## 5.0.2 - 2024-08-07

- Fixed a bug where SCA payment sources prevented subscriptions from starting. ([#304](https://github.com/craftcms/commerce-stripe/pull/304))
- Fixed a bug where the `hiddenClass` payment form parameter was not being applied correctly. ([#288](https://github.com/craftcms/commerce-stripe/pull/288))
- Fixed a SQL performance issue that occurred when upgrading. ([#190](https://github.com/craftcms/commerce-stripe/issues/190))
- Fixed a bug the billing issues payment form did not display correctly for subscriptions.
- Fixed a bug where the first payment source created was not set as the default.

## 5.0.1 - 2024-04-09

- Fixed a bug where floating point rounding precision could cause payments/refunds to fail. ([#296](https://github.com/craftcms/commerce-stripe/pull/296))
- Fixed a PHP error that could occur when handling a webhook request. ([#294](https://github.com/craftcms/commerce-stripe/issues/294))

## 5.0.0 - 2024-03-25

- Stripe now requires Craft Commerce 5.0.0-beta.1 or later.
