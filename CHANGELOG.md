# Release Notes for Stripe for Craft Commerce

## Unreleased

- Fixed a PHP error that occurred when opening the payment form modal on the Edit Order page with Craft Commerce 4.x installed. ([#303](https://github.com/craftcms/commerce/issues/303))

## 3.1.2 - 2024-04-09

- Fixed a PHP error that could occur when handling a webhook request. ([#294](https://github.com/craftcms/commerce-stripe/issues/294))
- Plans’ data is now updated when the associated plan is updated in Stripe. ([#240](https://github.com/craftcms/commerce-stripe/issues/240))

## 3.1.1 - 2023-05-10

- Stripe customers’ default payment methods are now kept in sync with Craft users’ primary payment sources. ([#235](https://github.com/craftcms/commerce-stripe/issues/235))
- Added `craft\commerce\stripe\services\Customers::EVENT_BEFORE_CREATE_CUSTOMER`. ([#233](https://github.com/craftcms/commerce-stripe/pull/233))
- Added `craft\commerce\stripe\events\SubscriptionRequestEvent::$plan`, which will be set to the plan being subscribed to. ([#141](https://github.com/craftcms/commerce-stripe/pull/141))

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
