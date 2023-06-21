# Release Notes for Stripe for Craft Commerce

## 4.0.0

- Stripe customer information is now updated when a user is updated.
- Added Stripe Checkout support for off-site checkout.
- Added support for the Stripe Billing Portal.
- Added support for for syncing customer payment methods.
- Added support to all Stripe payment methods including Apple Pay and Google Wallet.
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