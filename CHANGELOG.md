# Release Notes for Stripe for Craft Commerce

## Unreleased

- Stripe plugin now requires the `2022-11-15` version of the Stripe API.
- Added support to all Stripe payment methods including Apple Pay and Google Wallet. ([#223](https://github.com/craftcms/commerce-stripe/issues/223), [#222](https://github.com/craftcms/commerce-stripe/issues/222),[#212](https://github.com/craftcms/commerce-stripe/issues/212))
- Added support for the Stripe Billing Portal.
- Added support for Stripe Checkout, a off-site checkout page.
- When a plan is updated in Stripe, the associated Commerce plan is now also updated. ([#240](https://github.com/craftcms/commerce-stripe/issues/240))
- Stripe customer information is now updated when the associated user is updated.
- Added support for for syncing customer payment methods.
- Added the `commerce-stripe/sync/payment-methods` console command.
- Added the `commerce-stripe/customers/billing-portal-redirect` action.
- Added the `commerce-stripe/customers/create-setup-intent` action.
- Added the `craft\commerce\stripe\events\BuildSetupIntentRequestEvent` event.
- Added `craft\commerce\stripe\controllers\CustomersController`.
- Added `craft\commerce\stripe\gateways\PaymentIntents::getBillingPortalUrl()`.
- Removed `craft\commerce\stripe\events\BuildGatewayRequestEvent::$metadata`. Use `BuildGatewayRequestEvent::$request` instead.
- Removed `craft\commerce\stripe\base\Gateway::normalizePaymentToken`.
- Fixed a bug where `craft\commerce\stripe\base\SubscriptionGateway::getSubscriptionPlans()` was returning incorrectly formatted data.
- Deprecated the `commerce-stripe/default/fetch-plans` action.
- Added support for Stripe to log to the Craft log.

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
