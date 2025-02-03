# Release Notes for Stripe for Craft Commerce

## Unreleased

- Stripe now returns the correct HTTP error codes for invalid webhook requests.
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