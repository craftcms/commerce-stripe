# Release Notes for Stripe for Craft Commerce

## 1.0.10 - 2018-12-17

### Changed
- Switched to Stripe API version 2018-11-08.
- Improved handling of asynchronous 3D Secure transaction webhooks for some edge cases.

### Fixed
- Fixed an error that could occur when changing a subscription’s plan. ([#46](https://github.com/craftcms/commerce-stripe/issues/46))

## 1.0.9 - 2018-11-28

### Added
- `craft\commerce\stripe\services\Invoices` now fires a cancelable `beforeSaveInvoice` event.

### Fixed
- Fixed an error that occurred when calling `craft\commerce\stripe\services\Customers::getCustomer()`.
- Fixed an error that could occur when switching subscription plans. ([#34](https://github.com/craftcms/commerce-stripe/issues/34))

## 1.0.8 - 2018-10-18

### Added
- Added `craft\commerce\stripe\gateways\Gateway::EVENT_BEFORE_SUBSCRIBE`, which is triggered before subscribing a customer to a subscription plan. ([#31](https://github.com/craftcms/commerce-stripe/issues/31))

### Changed
- Stripe for Craft Commerce now requires Commerce 2.0.0-beta.12 or later.
- Switched to Stripe API version 2018-09-24.
- An exception is now thrown if webhook processing fails.

## 1.0.7 - 2018-09-26

### Removed
- Removed `craft\commerce\stripe\gateways\Gateway::getSubscriptionFormHtml()`.

### Fixed
- Fixed an error that occurred when attempting to subscribe to a plan, if the `trialDays` parameter wasn’t set.

## 1.0.6 - 2018-09-20

### Added
- Added `craft\commerce\stripe\gateways\Gateway::EVENT_RECEIVE_3DS_PAYMENT`, which is triggered after a successful 3D Secure transaction.

## 1.0.5 - 2018-09-17

### Fixed
- Fixed a bug where it wasn’t always possible to pay with a credit card when using Stripe SDK 6.17.1. ([#22](https://github.com/craftcms/commerce-stripe/issues/22))

## 1.0.4 - 2018-08-22

### Changed
- Switched to Stripe API version 2018-07-27.
- Subscription plan listings are no longer limited to 10 plans.

## 1.0.3 - 2018-06-05

### Fixed
- Fixed a PHP error that occurred when subscribing a user to a plan. ([#17](https://github.com/craftcms/commerce-stripe/issues/17))

## 1.0.2 - 2018-05-30

### Added
- Added support for paying via credit card tokens. ([#9](https://github.com/craftcms/commerce-stripe/issues/9))
- It’s now possible to pay on behalf of other users. ([#12](https://github.com/craftcms/commerce-stripe/issues/12))

## 1.0.1 - 2018-04-04

### Fixed
- Fixed a PHP error.

## 1.0.0 - 2018-04-03

- Initial release.
