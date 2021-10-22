# Release Notes for Stripe for Craft Commerce

## Unreleased

### Added
- Added support for the `payment_method.detached` Stripe webhook event.

### Fixed
- Fixed a bug where a customer would not save after being incorrectly detected as a duplicate. ([#97](https://github.com/craftcms/commerce-stripe/issues/97))
- Fixed a bug where plugins modifying the Stripe API request would have their metadata changes ignored. ([#85](https://github.com/craftcms/commerce-stripe/issues/85)) 

## 2.4.0 - 2021-10-13

### Added
- Added support for the `charge.refund.updated` Stripe webhook event.

### Changed
- Stripe for Craft Commerce now requires Craft CMS 3.7.0 and Craft Commerce 3.4.5 or later.

### Fixed
- Fixed a bug where a refund could fail asynchronously.

## 2.3.2.2 - 2021-10-10

### Fixed
- Fixed a bug that prevented multiple payments on a single order. ([#147](https://github.com/craftcms/commerce-stripe/issues/147))

## 2.3.2.1 - 2020-11-02

### Fixed
- Fixed a bug that caused additional Stripe gateways configured in the same installation to fail. ([#124](https://github.com/craftcms/commerce-stripe/issues/124))

## 2.3.2 - 2020-09-24

### Fixed
- Fixed a bug where changes to metadata in the `EVENT_BUILD_GATEWAY_REQUEST` event were being overridden. ([#120](https://github.com/craftcms/commerce-stripe/pull/120))
- Fixed a bug where the billing address’ state was not being passed when using Payment Intents.

## 2.3.1.1 - 2020-06-17

### Fixed
- Fixed minor typo.

## 2.3.1 - 2020-06-17

### Added
- Added `craft\commerce\stripe\gateways\Gateway::getTransactionHashFromWebhook()` to support mutex lock when processing a webhook.

## 2.3.0 - 2020-04-14

### Changed
- Updated `stripe-php` dependency. ([#95](https://github.com/craftcms/commerce-stripe/issues/95))
- JavaScript SDK is now loaded at the end of the body.
- Gateway `handleWebhook()` methods are now public.

### Fixed
- Fixed a bug that could cause viewport zooming on mobile devices. ([#94](https://github.com/craftcms/commerce-stripe/issues/94))

## 2.2.3 - 2019-12-12

### Fixed
- Fixed a javascript error in Payment Intents `paymentForm.js` when using IE 11. ([#92](https://github.com/craftcms/commerce-stripe/issues/92))

## 2.2.2 - 2019-11-11

### Fixed
- Fixed a PHP error when creating a new subscription plan. ([#91](https://github.com/craftcms/commerce-stripe/issues/91))

## 2.2.1 - 2019-10-29

### Fixed
- Fixed a bug that caused the plan selection dropdown to have the incorrect item selected.
- Fixed a bug that caused an order to be marked as complete multiple times with the “Stripe Charge” gateway.

## 2.2.0 - 2019-10-23

### Fixed
- Fixed a bug where Stripe webhook reponses could contain unnecessary JavaScript. ([##86](https://github.com/craftcms/commerce-stripe/issues/86))  

## 2.1.2 - 2019-09-04

### Added
- Added support for resolving subscription billing issues.
- Added `craft\commerce\stripe\models\forms\Subscription`.
- Added `craft\commerce\stripe\models\forms\SwitchPlans::$billingCycleAnchor`.
- Added `craft\commerce\stripe\services\Invoices::getUserInvoices()`.

### Changed
- Update Craft Commerce requirements to require Craft Commerce 2.2.
- Improved support for specifying trial when starting a subscription.
- Improved support for specifying billing cycle changes when switching subscription plans.

### Fixed
- Fixed a bug where payment form errors were not being caught and returned. ([#75](https://github.com/craftcms/commerce-stripe/issues/75))
- Fixed a PHP error caused by a change in the Stripe API.    

## 2.1.1 - 2019-08-15

### Fixed
- Fixed a bug where payment forms would sometimes throw an error. ([#59](https://github.com/craftcms/commerce-stripe/issues/59))
- Fixed a PHP error when retrieving the subscriptions next payment amount. ([#71](https://github.com/craftcms/commerce-stripe/issues/71))
- Fixed a bug where failed 3DS authentications would have no error code and error message.

## 2.1.0 - 2019-07-24

### Changed
- Update Craft Commerce requirements to allow for Craft Commerce 3.

### Fixed
- Fixed a bug where Webhook Signing Secret setting could not be set to an environment variable. ([#61](https://github.com/craftcms/commerce-stripe/issues/61))
- Fixed a bug where Commerce wouldn't correctly refund purchases by guest users using Payment Intents gateway. ([#64](https://github.com/craftcms/commerce-stripe/issues/64))

## 2.0.1.2 - 2019-07-08

### Fixed
- Fixed a bug where payment forms would not ensure that jQuery was present, before depending on it. ([#63](https://github.com/craftcms/commerce-stripe/issues/63))

## 2.0.1.1 - 2019-05-30

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
