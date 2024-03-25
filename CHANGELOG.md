# Release Notes for Stripe for Craft Commerce

## 4.1.2 - 2024-03-25

- Stripe now requires Craft Commerce 5.0.0-beta.1 or later.
- Fixed a bug where redirects could break when adding a new payment source. ([#259](https://github.com/craftcms/commerce-stripe/issues/259), [#289](https://github.com/craftcms/commerce-stripe/issues/289))
- Fixed a bug where payment history was not being updated when a payment was made on a subscription. ([#144](https://github.com/craftcms/commerce-stripe/issues/144))
- Subscription plans queries now return more than 100 plans. ([#104](https://github.com/craftcms/commerce-stripe/issues/104))

## 4.1.1 - 2024-01-12

- Fixed a bug where legacy default payment methods were not being set as default. ([#280](https://github.com/craftcms/commerce-stripe/pull/280))
- Fixed a bug that could cause duplicate payment sources to be created. ([#281](https://github.com/craftcms/commerce-stripe/pull/281))
- Fixed a bug where it wasn’t possible to access the Stripe instance from JavaScript. ([#275](https://github.com/craftcms/commerce-stripe/issues/275))
- Fixed a bug where not all enabled payment methods types were being shown when creating a payment source. ([#251](https://github.com/craftcms/commerce-stripe/issues/251), [#160](https://github.com/craftcms/commerce-stripe/pull/160))
- Fixed a bug where changing a partial payment amount wouldn’t update the payment intent. ([#279](https://github.com/craftcms/commerce-stripe/issues/279))

## 4.1.0 - 2023-12-19

- Stripe for Craft Commerce now requires Commerce 4.3.3 or later.
- It is now possible to create SEPA and Bacs Direct Debit payment sources.
- Payment method data is now stored in expanded form within transaction response data. ([#276](https://github.com/craftcms/commerce-stripe/pull/276))
- Billing address information is now passed to the payment intent. ([#257](https://github.com/craftcms/commerce-stripe/issues/257), [#258](https://github.com/craftcms/commerce-stripe/issues/263))
- Fixed a bug where it wasn’t possible to pay using the SEPA Direct Debit payment method. ([#265](https://github.com/craftcms/commerce-stripe/issues/265))
- Fixed a bug where failed PayPal payments would cause infinite redirects. ([#266](https://github.com/craftcms/commerce-stripe/issues/266))
- Fixed a bug where JavaScript files were being served incorrectly. ([#270](https://github.com/craftcms/commerce-stripe/issues/270))
- Added `craft\commerce\stripe\SubscriptionGateway::handlePaymentIntentSucceeded()`.

## 4.0.1.1 - 2023-10-25

- Restored support for backend payments using the old payment form.
- Fixed missing icon.

## 4.0.1 - 2023-09-28

- Fixed a PHP error that occurred when switching a subscription’s plan.

## 4.0.0 - 2023-09-13

- Added support for all of Stripe’s payment methods, including Apple Pay and Google Wallet. ([#223](https://github.com/craftcms/commerce-stripe/issues/223), [#222](https://github.com/craftcms/commerce-stripe/issues/222),[#212](https://github.com/craftcms/commerce-stripe/issues/212))
- Added support for [Stripe Billing](https://stripe.com/billing).
- Added support for [Stripe Checkout](https://stripe.com/payments/checkout).
- Added support for syncing customer payment methods.
- Plans are now kept in sync with Stripe plans. ([#240](https://github.com/craftcms/commerce-stripe/issues/240))
- Customer information is now kept in sync with Stripe customers.
- Improved logging.
- Stripe now uses the `2022-11-15` version of the Stripe API.
- Added the `commerce-stripe/customers/billing-portal-redirect` action.
- Added the `commerce-stripe/customers/create-setup-intent` action.
- Added the `commerce-stripe/sync/payment-methods` command.
- Added `craft\commerce\stripe\events\BuildSetupIntentRequestEvent`.
- Added `craft\commerce\stripe\gateways\PaymentIntents::getBillingPortalUrl()`.
- Removed `craft\commerce\stripe\base\Gateway::normalizePaymentToken()`.
- Removed `craft\commerce\stripe\events\BuildGatewayRequestEvent::$metadata`. `BuildGatewayRequestEvent::$request` should be used instead.
- Deprecated the `commerce-stripe/default/fetch-plans` action.
- Deprecated creating new payment sources via the `commerce/subscriptions/subscribe` action.
- Fixed a bug where `craft\commerce\stripe\base\SubscriptionGateway::getSubscriptionPlans()` was returning incorrectly-formatted data.

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
