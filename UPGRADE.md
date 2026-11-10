# Upgrading to Stripe for Craft Commerce 7.0 (Commerce 6)

Stripe for Craft Commerce 7.0 supports Craft Commerce 6. It still uses Stripe’s official `stripe/stripe-php` SDK and the same Stripe API version (`2026-01-28.clover`) as 6.0.

## Requirements

- Craft CMS 6.0 and Craft Commerce 6.0 or later
- PHP 8.5 or later
- `stripe/stripe-php` 13.16 or later
- `craftcms/yii2-adapter` is not needed.

## Upgrade steps

1. Update to Stripe for Craft Commerce 5.2.3 or later and Craft Commerce 5 first.
2. If you use subscriptions, move them to the [Stripe plugin for Craft CMS](https://plugins.craftcms.com/stripe) while you’re still on Craft Commerce 5. See [Subscriptions](#subscriptions).
3. Upgrade your project to Craft 6 and Craft Commerce 6.
4. Update the plugin: `composer require craftcms/commerce-stripe:^7.0`.
5. Review the breaking changes below.

Existing Stripe gateways keep working: gateways saved with the old `craft\commerce\stripe\gateways\PaymentIntents` type load as `CraftCms\CommerceStripe\Gateway`, and save with the new class name the next time they’re saved. Saved payment sources, Stripe customers and payment intents are kept.

## Breaking changes

### Subscriptions

Craft Commerce 6 doesn’t support subscriptions, so all of the plugin’s subscription, plan and invoice features were removed:

- Subscribing, canceling, reactivating and switching plans, and the plan settings in the control panel.
- The `invoice.*`, `customer.subscription.*` and `plan.*` webhooks are no longer handled. You can remove them from your webhook endpoint in Stripe.
- The `chargeInvoicesImmediately` config setting (`config/commerce-stripe.php`). The plugin has no config settings anymore, so you can delete the file.

Subscriptions and invoices still exist in Stripe and keep billing your customers. The plugin keeps its `stripe_invoices` table, but no longer reads or writes it. Uninstalling the plugin drops it along with the plugin’s other tables.

The [Stripe plugin for Craft CMS](https://plugins.craftcms.com/stripe) can migrate your Commerce subscriptions. Run its `stripe/commerce/migrate` command while your project is still on Craft Commerce 5. See [Moving Subscriptions to the Stripe Plugin](README.md#moving-subscriptions-to-the-stripe-plugin).

These classes were removed with no replacement:

| Removed |
| --- |
| `craft\commerce\stripe\base\SubscriptionGateway` (its payment source and webhook methods moved to `CraftCms\CommerceStripe\Gateway`) |
| `craft\commerce\stripe\events\CreateInvoiceEvent` |
| `craft\commerce\stripe\events\SaveInvoiceEvent` |
| `craft\commerce\stripe\events\SubscriptionRequestEvent` |
| `craft\commerce\stripe\events\Receive3dsPaymentEvent` (deprecated since 2.0, and not dispatched) |
| `craft\commerce\stripe\models\Invoice` |
| `craft\commerce\stripe\models\Plan` |
| `craft\commerce\stripe\models\Settings` |
| `craft\commerce\stripe\models\forms\CancelSubscription` |
| `craft\commerce\stripe\models\forms\Subscription` |
| `craft\commerce\stripe\models\forms\SwitchPlans` |
| `craft\commerce\stripe\records\Invoice` |
| `craft\commerce\stripe\responses\SubscriptionResponse` |
| `craft\commerce\stripe\services\Invoices` (and `Plugin::getInvoices()`) |
| `craft\commerce\stripe\web\assets\plansettings\PlanSettingsAsset` |

These gateway methods were removed with it: `cancelSubscription()`, `getCancelSubscriptionFormHtml()`, `getCancelSubscriptionFormModel()`, `getNextPaymentAmount()`, `getPlanModel()`, `getPlanSettingsHtml()`, `getSubscriptionFormModel()`, `getSubscriptionPayments()`, `refreshPaymentHistory()`, `getSubscriptionPlanByReference()`, `getSubscriptionPlans()`, `getSwitchPlansFormHtml()`, `getSwitchPlansFormModel()`, `reactivateSubscription()`, `supportsPlanSwitch()`, `supportsReactivation()`, `switchSubscriptionPlan()`, `previewSwitchCost()`, `subscribe()`, `getBillingIssueDescription()`, `getBillingIssueResolveFormHtml()`, `getHasBillingIssues()` and `getExpandedSubscriptionData()`.

These templates were removed: `commerce-stripe/_subscriptions/meta`, `commerce-stripe/cancelSubscriptionForm`, `commerce-stripe/switchPlansForm` and `commerce-stripe/planSettings`.

The `commerce-stripe/default/fetch-plans` action was removed.

### Events

The plugin’s Yii events are now Laravel event classes. Listen for them with `Illuminate\Support\Facades\Event::listen()`. The event properties keep their names, and each event also has a `gateway` property.

| Before | After |
| --- | --- |
| `craft\commerce\stripe\base\Gateway::EVENT_BUILD_GATEWAY_REQUEST` (`BuildGatewayRequestEvent`) | `CraftCms\CommerceStripe\Events\GatewayRequestBuilding` |
| `craft\commerce\stripe\base\Gateway::EVENT_BUILD_SETUP_INTENT_REQUEST` (`BuildSetupIntentRequestEvent`) | `CraftCms\CommerceStripe\Events\SetupIntentRequestBuilding` |
| `craft\commerce\stripe\base\Gateway::EVENT_RECEIVE_WEBHOOK` (`ReceiveWebhookEvent`) | `CraftCms\CommerceStripe\Events\WebhookReceived` |
| `craft\commerce\stripe\gateways\PaymentIntents::EVENT_BEFORE_CONFIRM_PAYMENT_INTENT` (`PaymentIntentConfirmationEvent`) | `CraftCms\CommerceStripe\Events\PaymentIntentConfirming` |
| `craft\commerce\stripe\gateways\PaymentIntents::EVENT_BEFORE_SERVER_SIDE_CONFIRMATION` (`ServerSideConfirmationEvent`) | `CraftCms\CommerceStripe\Events\ServerSideConfirming` |
| `craft\commerce\stripe\gateways\PaymentIntents::EVENT_BEFORE_RENDER_PAYMENT_FORM` (`PaymentFormEvent`) | `CraftCms\CommerceStripe\Events\PaymentFormRendering` |
| `craft\commerce\stripe\services\Customers::EVENT_BEFORE_CREATE_CUSTOMER` (`CreateCustomerEvent`) | `CraftCms\CommerceStripe\Events\CustomerCreating` |
| `craft\commerce\stripe\base\Gateway::EVENT_BUILD_PAYMENT_INTENT_REQUEST` | No replacement. It was never dispatched; use `GatewayRequestBuilding`. |
| `craft\commerce\stripe\base\SubscriptionGateway::EVENT_BEFORE_SUBSCRIBE` | No replacement (subscriptions were removed) |
| `craft\commerce\stripe\base\SubscriptionGateway::EVENT_CREATE_INVOICE` | No replacement (subscriptions were removed) |
| `craft\commerce\stripe\services\Invoices::EVENT_BEFORE_SAVE_INVOICE` | No replacement (subscriptions were removed) |
| `craft\commerce\stripe\services\Invoices::EVENT_SAVE_INVOICE` | No replacement (subscriptions were removed) |

Before:

```php
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\gateways\PaymentIntents;
use yii\base\Event;

Event::on(PaymentIntents::class, PaymentIntents::EVENT_BUILD_GATEWAY_REQUEST, function(BuildGatewayRequestEvent $e) {
    $e->request['metadata']['shipping_method'] = $e->transaction->getOrder()->shippingMethodHandle;
});
```

After:

```php
use CraftCms\CommerceStripe\Events\GatewayRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(GatewayRequestBuilding $event): void {
    $event->request['metadata']['shipping_method'] = $event->transaction->getOrder()->shippingMethodHandle;
});
```

`ServerSideConfirming` uses Craft’s `ValidatableEvent`, so you still reject a payment by setting `$event->isValid = false` and `$event->errorMessage`. Its `confirmationToken` is only retrieved from Stripe when a listener is registered, as before.

### Classes and namespaces

| Before | After |
| --- | --- |
| `craft\commerce\stripe\Plugin` | `CraftCms\CommerceStripe\Plugin` |
| `craft\commerce\stripe\gateways\PaymentIntents` | `CraftCms\CommerceStripe\Gateway` (the old name is kept as an alias) |
| `craft\commerce\stripe\base\Gateway` | `CraftCms\CommerceStripe\Gateway` |
| `craft\commerce\stripe\services\Customers` | `CraftCms\CommerceStripe\Customers` |
| `craft\commerce\stripe\services\PaymentIntents` | `CraftCms\CommerceStripe\PaymentIntents` |
| `craft\commerce\stripe\services\PaymentMethods` | `CraftCms\CommerceStripe\PaymentMethods` |
| `craft\commerce\stripe\models\Customer` and `craft\commerce\stripe\records\Customer` | `CraftCms\CommerceStripe\Models\Customer` (Eloquent) |
| `craft\commerce\stripe\models\PaymentIntent` and `craft\commerce\stripe\records\PaymentIntent` | `CraftCms\CommerceStripe\Models\PaymentIntent` (Eloquent) |
| `craft\commerce\stripe\models\forms\payment\PaymentIntent` | `CraftCms\CommerceStripe\Forms\PaymentIntentForm` |
| `craft\commerce\stripe\responses\PaymentIntentResponse` | `CraftCms\CommerceStripe\Responses\PaymentIntentResponse` |
| `craft\commerce\stripe\responses\CheckoutSessionResponse` | `CraftCms\CommerceStripe\Responses\CheckoutSessionResponse` |
| `craft\commerce\stripe\responses\ServerConfirmedPaymentIntentResponse` | `CraftCms\CommerceStripe\Responses\ServerConfirmedPaymentIntentResponse` |
| `craft\commerce\stripe\errors\CustomerException` | `CraftCms\CommerceStripe\Exceptions\CustomerException` |
| `craft\commerce\stripe\errors\PaymentSourceException` | `CraftCms\CommerceStripe\Exceptions\PaymentSourceException` |
| `craft\commerce\stripe\db\Table` | `CraftCms\CommerceStripe\Database\Table` |
| `craft\commerce\stripe\controllers\CustomersController` | `CraftCms\CommerceStripe\Http\Controllers\CustomersController` |
| `craft\commerce\stripe\controllers\PaymentsController` | `CraftCms\CommerceStripe\Http\Controllers\PaymentsController` |
| `craft\commerce\stripe\controllers\DefaultController` | `CraftCms\CommerceStripe\Http\Controllers\SyncPaymentSourcesController` |
| `craft\commerce\stripe\console\controllers\ResetDataController` | `CraftCms\CommerceStripe\Console\Commands\ResetDataCommand` |
| `craft\commerce\stripe\console\controllers\SyncController` | `CraftCms\CommerceStripe\Console\Commands\SyncPaymentSourcesCommand` |

The services are singletons in Laravel’s container. Replace `Plugin::getInstance()->getCustomers()`, `getPaymentIntents()` and `getPaymentMethods()` with `app(Customers::class)`, `app(PaymentIntents::class)` and `app(PaymentMethods::class)`, or inject them.

`CraftCms\CommerceStripe\Models\Customer` and `PaymentIntent` are Eloquent models, and the services’ `saveCustomer()` and `savePaymentIntent()` methods take them. The Yii models and Active Record classes are gone.

`Gateway::handleWebhook()` and the other `handle*()` webhook methods are still public. `Gateway::getSettingsHtml()` is replaced by `settingsUi()`.

### Payment form and Twig

`gateway.getPaymentFormHtml(params)` works as before and accepts the same params (`paymentFormType`, `serverSideConfirmation`, `elementsOptions`, `billingDetails`, `appearance`, `elementOptions`, `order`, `locale`, `fonts`, `errorMessageClasses`, `submitButtonClasses`, `submitButtonText`, `processingButtonText`, `hiddenClass`, `scenario`). The form posts the same fields to the same actions, and the payment form JavaScript works as before.

The gateway now builds its payment form with Commerce 6’s `paymentFormUi()`, rather than Twig templates. These templates were removed:

- `commerce-stripe/paymentForms/elementsForm`
- `commerce-stripe/paymentForms/checkoutForm`
- `commerce-stripe/paymentForms/oldIntentsForm`
- `commerce-stripe/gatewaySettings/intentsSettings` (the settings are built with `settingsUi()`)
- `commerce-stripe/utilities/_sync`

To change the form, extend the gateway and override `paymentFormUi()`, or listen for `PaymentFormRendering` to change its params.

`PaymentIntents::getOldPaymentFormHtml()` was removed, along with the `IntentsFormAsset` and `ElementsFormAsset` asset bundles. The payment form JavaScript is registered inline when the form is rendered.

### Webhooks

Webhooks are verified and handled as before. The `charge.refunded` webhook is no longer listed as handled, because the plugin did nothing with it; refunds are updated by `refund.updated`. See the [README](README.md#webhooks) for the events to select in Stripe.

### Actions

The actions keep their URLs (`actions/commerce-stripe/...`):

- `commerce-stripe/payments/save-payment-intent` (POST, JSON) still works for guests.
- `commerce-stripe/customers/billing-portal-redirect` (POST), `commerce-stripe/customers/create-setup-intent` (POST, JSON) and `commerce-stripe/customers/confirm-setup-intent` (GET) still require a logged-in user. Guests now get a 401 response, rather than a “No user logged in.” failure response.
- `commerce-stripe/customers/confirm-setup-intent` now responds with 403 if the setup intent belongs to another user’s Stripe customer.
- `commerce-stripe/default/sync-payment-sources` (POST) still requires an admin.
- `commerce-stripe/default/fetch-plans` was removed.

### Console commands

The console commands are now Artisan commands:

| Before | After |
| --- | --- |
| `php craft commerce-stripe/reset-data` | `php artisan commerce-stripe:reset-data` |
| `php craft commerce-stripe/sync/payment-sources` | `php artisan commerce-stripe:sync:payment-sources` |

The old names still work as aliases, prefixed with `craft:` like Craft’s own commands (`php artisan craft:commerce-stripe/reset-data`). `commerce-stripe:reset-data` still accepts `--force`.

### Utilities

The **Stripe Sync** utility (`craft\commerce\stripe\utilities\Sync`) was removed. Run `php artisan commerce-stripe:sync:payment-sources` instead, or post a gateway’s `gatewayId` to the `commerce-stripe/default/sync-payment-sources` action as an admin.
