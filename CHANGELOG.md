# Release Notes for Stripe for Craft Commerce

## Unreleased

### Fixed
- Fixed an error with Payment Intents gateway where it was impossible to pay while not logged in some more. ([#60](https://github.com/craftcms/commerce-stripe/issues/60))

## 2.0.1 - 2019-05-30

### Changed
- Country ISO code is now submitted to Stripe, instead of the country name. ([#59](https://github.com/craftcms/commerce-stripe/issues/59))

### Fixed
- Fixed an error with Payment Intents gateway where it was impossible to pay while not logged in. ([#60](https://github.com/craftcms/commerce-stripe/issues/60))

## 2.0.0 - 2019-05-14

### Added
- Added the Stripe Payment Intents gateway, which is compatible with [3D Secure 2](https://stripe.com/guides/3d-secure-2).
- Added `craft\commerce\stripe\base\Gateway`.
- Added `craft\commerce\stripe\base\SubscriptionGateway`.
- Added `craft\commerce\stripe\gateways\PaymentIntents`.
- Added `craft\commerce\stripe\models\PaymentIntent`.
- Added `craft\commerce\stripe\models\forms\PaymentIntent`.
- Added `craft\commerce\stripe\records\PaymentIntent`.
- Added `craft\commerce\stripe\responses\PaymentIntentResponse`.
- Added `craft\commerce\stripe\services\Customers::getCustomerById()`.
- Added `craft\commerce\stripe\services\PaymentIntents`.

### Changed
- Switched to Stripe API version 2019-03-14.
- Renamed the Stripe gateway to “Stripe Charge”.
- Renamed `craft\commerce\stripe\models\forms\Payment` to `craft\commerce\stripe\models\forms\Charge`.
- Renamed `craft\commerce\stripe\responses\PaymentResponse` to `craft\commerce\stripe\responses\ChargeResponse`.
- Renamed `craft\commerce\stripe\web\PaymentResponse` to `craft\commerce\stripe\responses\ChargeResponse`.

### Deprecated
- Deprecated `craft\commerce\stripe\events\Receive3dsPayment`.
- Deprecated `craft\commerce\stripe\gateways\Gateway`.

### Fixed
- Fixed a bug where it was not possible to save a payment source if the customer had been deleted on Stripe. ([#35](https://github.com/craftcms/commerce-stripe/issues/35)).
- Fixed a bug where the `craft\commerce\services\Subscriptions::EVENT_AFTER_EXPIRE_SUBSCRIPTION` event wouldn’t be triggered for subscriptions that were canceled immediately. ([#47](https://github.com/craftcms/commerce-stripe/issues/47)).

## 1.2.2 - 2019-04-30

### Added
- Added support for `craft\commerce\base\SubscriptionResponseInterface::isInactive()`.

### Changed
- Stripe for Craft Commerce now requires Craft Commerce 2.1.4 or later.

## 1.2.1 - 2019-04-16

### Added
- Billing address information is now provided to Stripe on payment, if available. ([#43](https://github.com/craftcms/commerce-stripe/issues/43))

### Fixed
- Fixed a bug where it was not possible to fetch customer data if the Secret API Key was set to an environment variable ([#54](https://github.com/craftcms/commerce-stripe/issues/54)) .

## 1.2.0 - 2019-03-12

### Added
- Added `craft\commerce\stripe\services\Invoices::getInvoiceByReference()`.
- Added `craft\commerce\stripe\gateways\Gateway::refreshPaymentHistory()`.

## 1.1.0 - 2019-03-02

### Added
- The Secret API Key, Publishable API Key, and Webhook Signing Secret settings can now be set to environment variables.
- Added `craft\commerce\stripe\events\BuildGatewayRequestEvent::$request`.

### Changed
- Stripe for Craft Commerce now requires Craft CMS 3.1.5 or later.
- Stripe for Craft Commerce now requires Craft Commerce 2.0.0 or later.

### Deprecated
- Deprecated `craft\commerce\stripe\events\BuildGatewayRequestEvent::$metadata`. The new `$request` property should be used instead.

### Fixed
- Fixed an error that occurred when paying for an order on a console request.

## 1.0.10 - 2018-12-17

### Changed
- Switched to Stripe API version 2018-11-08.
- Improved handling of asynchronous 3D Secure transaction webhooks for some edge cases.

### Fixed
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
