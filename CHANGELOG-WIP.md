# Commerce Stripe Changelog (WIP)

## 6.0.0

### Added
- Added the `serverSideConfirmation` payment form option, which creates and confirms the Payment Intent on the server when the payment form is submitted, so Commerce’s order and payment amount checks run before the payment is confirmed. ([#355](https://github.com/craftcms/commerce-stripe/issues/355))
- Added the `elementsOptions` and `billingDetails` payment form options, for use with `serverSideConfirmation`.
- Added `craft\commerce\stripe\models\forms\payment\PaymentIntent::$confirmationToken`.
- Added `craft\commerce\stripe\responses\ServerConfirmedPaymentIntentResponse`.
- Added `craft\commerce\stripe\events\PaymentFormEvent`.
- Added `craft\commerce\stripe\events\PaymentIntentConfirmationEvent::$transaction`.
- Added `craft\commerce\stripe\events\ServerSideConfirmationEvent`.
- Added `craft\commerce\stripe\gateways\PaymentIntents::EVENT_BEFORE_RENDER_PAYMENT_FORM`.
- Added `craft\commerce\stripe\gateways\PaymentIntents::EVENT_BEFORE_SERVER_SIDE_CONFIRMATION`.

### Changed
- Changes to `amount` and `currency` made by `beforeConfirmPaymentIntent` event handlers are now ignored when confirming server-side.
- Stripe for Craft Commerce now requires `stripe/stripe-php` 13.16 or later.
- Updated Stripe API version from `2022-11-15` to `2026-01-28.clover`.

