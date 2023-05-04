# Release Notes for Stripe for Craft Commerce

## Unreleased

- Switching a user’s primary payment source now ensures it is also set at the default payment method in Stripe.

## 3.1.0 - 2022-01-29

- Added the `commerce-stripe/reset-data` command.

## 3.0.3 - 2022-11-23

### Fixed
- Fixed a PHP error that occurred when switching a subscription’s plan. ([#3035](https://github.com/craftcms/commerce/issues/3035))

## 3.0.2 - 2022-11-17

### Added
- Added `craft\commerce\stripe\gateways\PaymentIntents::EVENT_BEFORE_CONFIRM_PAYMENT_INTENT`. ([#221](https://github.com/craftcms/commerce-stripe/pull/221))

### Fixed
- Fixed a PHP error that occurred where switching a subscription plan.

## 3.0.1 - 2022-06-16

### Fixed
- Fixed a bug where billing address wasn’t being sent to Stripe.
- Fixed incorrect type on `craft\commerce\stripe\models\forms\SwitchPlans::$billingCycleAnchor`.

## 3.0.0 - 2022-05-04

### Added
- Added Craft CMS 4 and Craft Commerce 4 compatibility.
- Payment Intents gateway settings now support environment variables.

### Removed
- Removed the Charge gateway.

### Fixed
- Fixed an error that would occur when calling `craft\commerce\stripe\models\PaymentIntent::getTransaction()`.
