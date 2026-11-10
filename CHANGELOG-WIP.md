# Commerce Stripe Changelog (WIP)

## 6.0.0

### Added
- Added the `serverSideConfirmation` payment form option, which creates and confirms the Payment Intent on the server when the payment form is submitted, so Commerce’s order and payment amount checks run before the payment is confirmed. ([#355](https://github.com/craftcms/commerce-stripe/issues/355))
- Added the `elementsOptions` and `billingDetails` payment form options, for use with `serverSideConfirmation`.
- Added `craft\commerce\stripe\models\forms\payment\PaymentIntent::$confirmationToken`.
- Added `craft\commerce\stripe\responses\ServerConfirmedPaymentIntentResponse`.

### Changed
- Updated Stripe API version from `2022-11-15` to `2026-01-28.clover`.

