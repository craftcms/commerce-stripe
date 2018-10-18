# Release Notes for Stripe for Craft Commerce

## 1.0.7 - 2018-09-26

### Removed

- Removed `craft\commerce\stripe\gateways\Gateway::getSubscriptionFormHtml()`.

### Fixed

- Fixed an error that occurred when attempting to subscribe to a plan, if the `trialDays` parameter wasn’t set.

## 1.0.6 - 2018-09-20

- Added the `craft\commerce\stripe\gateways\Gateway::EVENT_RECEIVE_3DS_PAYMENT` event that is fired after a successful 3D Secure transaction confirmation by webhook.

## 1.0.5 - 2018-09-17

- Fixed a bug where it was sometimes impossible to pay with a card when using Stripe SDK 6.17.1 ([#22](https://github.com/craftcms/commerce-stripe/issues/22))

## 1.0.4 - 2018-08-22

- Switch Stripe API version to '2018-07-27'.
- Stop limiting subscription plan listing to 10 subscription plans.

## 1.0.3

- Fixed a PHP error that occurred when subscribing a user to a plan. ([#17](https://github.com/craftcms/commerce-stripe/issues/17))

## 1.0.2

- Added support for card tokens, not just payment sources. ([#9](https://github.com/craftcms/commerce-stripe/issues/9))
- Allow creating payment source on behalf of users. ([#12](https://github.com/craftcms/commerce-stripe/issues/12))

## 1.0.1

- Fix a bug with a missing dependency.

## 1.0.0

- Initial release.
