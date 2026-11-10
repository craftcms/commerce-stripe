<?php

declare(strict_types=1);

use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\View\HtmlStack;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;
use CraftCms\Commerce\Payment\PaymentSources;
use CraftCms\CommerceStripe\Events\PaymentFormRendering;
use CraftCms\CommerceStripe\Forms\PaymentIntentForm;
use CraftCms\CommerceStripe\Gateway;
use CraftCms\CommerceStripe\Models\Customer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Returns the data attributes of the Payment Element container in the payment form HTML.
 *
 * @return array<string, string>
 */
function paymentElementData(string $html): array
{
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    $container = new DOMXPath($document)->query('//div[contains(@class, "stripe-payment-elements-form")]')->item(0);
    expect($container)->not->toBeNull();

    $data = [];
    foreach ($container->attributes as $attribute) {
        if (str_starts_with($attribute->name, 'data-')) {
            $data[substr($attribute->name, 5)] = $attribute->value;
        }
    }

    return $data;
}

it('registers the gateway type', function() {
    expect(app(GatewayTypes::class)->types()->all())->toContain(Gateway::class);
});

it('loads gateways saved with the old class name', function() {
    $gateway = app(Gateways::class)->createGateway([
        'type' => 'craft\\commerce\\stripe\\gateways\\PaymentIntents',
        'settings' => [
            'publishableKey' => '$STRIPE_PUBLISHABLE_KEY',
            'apiKey' => '$STRIPE_SECRET_KEY',
            'signingSecret' => '$STRIPE_SIGNING_SECRET',
            'sendReceiptEmail' => '$STRIPE_SEND_RECEIPT',
        ],
    ]);

    expect($gateway)->toBeInstanceOf(Gateway::class)
        ->and($gateway->getSettings())->toBe([
            'publishableKey' => '$STRIPE_PUBLISHABLE_KEY',
            'apiKey' => '$STRIPE_SECRET_KEY',
            'signingSecret' => '$STRIPE_SIGNING_SECRET',
            'sendReceiptEmail' => '$STRIPE_SEND_RECEIPT',
        ])
        ->and($gateway->getConfig()['type'])->toBe(Gateway::class);
});

it('parses environment variables in the settings', function() {
    $_SERVER['STRIPE_SECRET_KEY'] = 'sk_test_env';
    $_SERVER['STRIPE_SEND_RECEIPT'] = 'true';
    $gateway = stripeGateway(['apiKey' => '$STRIPE_SECRET_KEY', 'sendReceiptEmail' => '$STRIPE_SEND_RECEIPT'], save: false);

    expect($gateway->getApiKey())->toBe('sk_test_env')
        ->and($gateway->getApiKey(false))->toBe('$STRIPE_SECRET_KEY')
        ->and($gateway->getSendReceiptEmail())->toBeTrue()
        ->and($gateway->getSendReceiptEmail(false))->toBe('$STRIPE_SEND_RECEIPT');

    unset($_SERVER['STRIPE_SECRET_KEY'], $_SERVER['STRIPE_SEND_RECEIPT']);
});

it('requires the API keys', function() {
    $gateway = stripeGateway(['publishableKey' => '', 'apiKey' => ''], save: false);

    expect($gateway->validate())->toBeFalse()
        ->and($gateway->errors()->keys())->toContain('publishableKey', 'apiKey')
        ->and($gateway->errors()->keys())->not->toContain('signingSecret');
});

it('renders the settings', function() {
    $html = stripeGateway(['publishableKey' => '$STRIPE_PUBLISHABLE_KEY', 'apiKey' => '$STRIPE_SECRET_KEY', 'signingSecret' => '$STRIPE_SIGNING_SECRET'], save: false)
        ->getSettingsHtml();

    expect($html)->toContain('name="publishableKey"')
        ->toContain('name="apiKey"')
        ->toContain('name="signingSecret"')
        ->toContain('$STRIPE_PUBLISHABLE_KEY')
        ->toContain('$STRIPE_SECRET_KEY')
        ->toContain('$STRIPE_SIGNING_SECRET')
        ->toContain('Publishable API Key')
        ->toContain('Webhook Signing Secret');
});

it('renders the Payment Element form and registers its JavaScript', function() {
    $gateway = stripeGateway();

    $html = $gateway->getPaymentFormHtml([
        'appearance' => ['theme' => 'night'],
        'submitButtonText' => 'Pay now',
        'submitButtonClasses' => 'btn',
        'errorMessageClasses' => 'text-red',
        'locale' => 'de',
    ]);

    $data = paymentElementData($html);
    expect($data['publishablekey'])->toBe('pk_test_publishable')
        ->and($data['gateway-id'])->toBe((string)$gateway->id)
        ->and($data['payment-form-namespace'])->toBe('paymentForm[stripe]')
        ->and($data['client-scenario'])->toBe('payment')
        ->and($data['locale'])->toBe('de')
        ->and(json_decode($data['appearance'], true))->toBe(['theme' => 'night'])
        ->and(json_decode($data['element-options'], true))->toBe(['layout' => ['type' => 'tabs']])
        ->and($data['server-side-confirmation'])->toBe('false')
        ->and($data['complete-payment-action-url'])->toContain('commerce/payments/complete-payment')
        ->and($data['confirm-setup-intent-url'])->toContain('commerce-stripe/customers/confirm-setup-intent')
        ->and($data['users-session-info-url'])->toContain('users/session-info')
        ->and($html)->toContain('<button class="stripe-payment-elements-submit-button btn">Pay now</button>')
        ->toContain('class="hidden stripe-error-message text-red"')
        ->toContain('class="stripe-payment-element"')
        ->not->toContain('inert');

    $body = app(HtmlStack::class)->bodyHtml();
    expect($body)->toContain('https://js.stripe.com/v3/')
        ->toContain('class PaymentIntentsElements');
});

it('escapes the payment form params', function() {
    $html = stripeGateway()->getPaymentFormHtml(['submitButtonText' => '<script>alert(1)</script>', 'processingButtonText' => '"><b>']);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('"><b>')
        ->and(paymentElementData($html)['processing-button-text'])->toBe('"><b>');
});

it('adds the order’s billing details to the Payment Element', function() {
    $gateway = stripeGateway();
    $order = stripeTransaction($gateway, 25)->getOrder();
    $order->setBillingAddress([
        'fullName' => 'Jenny Andrews',
        'addressLine1' => '1 Main St',
        'locality' => 'Portland',
        'administrativeArea' => 'OR',
        'postalCode' => '97201',
        'countryCode' => 'US',
    ]);

    $data = paymentElementData($gateway->getPaymentFormHtml(['order' => $order]));

    expect(json_decode($data['element-options'], true)['defaultValues']['billingDetails'])->toBe([
        'name' => 'Jenny Andrews',
        'email' => 'guest@example.com',
        'address' => [
            'country' => 'US',
            'line1' => '1 Main St',
            'line2' => '',
            'city' => 'Portland',
            'postal_code' => '97201',
            'state' => 'OR',
        ],
    ]);
});

it('creates the Payment Element without a payment intent for server-side confirmation', function() {
    $gateway = stripeGateway(paymentType: 'authorize');
    $order = stripeTransaction($gateway)->getOrder();
    $order->setPaymentAmount(0);

    $data = paymentElementData($gateway->getPaymentFormHtml([
        'order' => $order,
        'serverSideConfirmation' => true,
        'elementsOptions' => ['paymentMethodTypes' => ['card']],
    ]));

    expect($data['server-side-confirmation'])->toBe('true')
        ->and(json_decode($data['elements-options'], true))->toBe([
            'mode' => 'payment',
            'amount' => 0,
            'currency' => 'usd',
            'captureMethod' => 'manual',
            'paymentMethodCreation' => 'manual',
            'paymentMethodTypes' => ['card'],
        ]);
});

it('lets listeners change the payment form params', function() {
    Event::listen(function(PaymentFormRendering $event) {
        $event->params['appearance']['theme'] = 'flat';
    });

    $data = paymentElementData(stripeGateway()->getPaymentFormHtml([]));

    expect(json_decode($data['appearance'], true))->toBe(['theme' => 'flat']);
});

it('renders the Checkout form with the payment form type the gateway reads', function() {
    $gateway = stripeGateway();

    $html = $gateway->getPaymentFormHtml(['paymentFormType' => 'checkout', 'submitButtonText' => 'Checkout']);

    expect($html)->toMatch('/<input type="hidden"[^>]*name="paymentFormType"[^>]*value="checkout"/')
        ->toContain('type="submit"')
        ->toContain('value="Checkout"')
        ->not->toContain('stripe-payment-elements-form');

    $form = new PaymentIntentForm();
    $form->setAttributes(['paymentFormType' => 'checkout']);
    expect($form->paymentFormType)->toBe('checkout');
});

it('shows an error when no scenario is set', function() {
    expect(html_entity_decode(stripeGateway()->getPaymentFormHtml(['scenario' => ''])))
        ->toContain('Commerce Stripe 4.0+ requires a scenario is set on the payment form.');
});

it('has no payment confirmation form', function() {
    expect(stripeGateway()->getPaymentConfirmationFormHtml([]))->toBe('');
});

it('creates a payment source from a payment method', function() {
    $this->stripe
        ->respond('GET', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer'])
        ->respond('POST', '/v1/payment_methods/pm_card/attach', cardPaymentMethod(['customer' => 'cus_test', 'card' => ['brand' => 'mastercard', 'last4' => '4444']]))
        ->respond('POST', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer']);
    $gateway = stripeGateway();
    $user = stripeUser();

    $paymentSource = app(PaymentSources::class)->createPaymentSource($user->id, $gateway, new PaymentIntentForm(['paymentMethodId' => 'pm_card']));

    expect($paymentSource->id)->not->toBeNull()
        ->and($paymentSource->token)->toBe('pm_card')
        ->and($paymentSource->description)->toBe('Mastercard ending in ••••4444')
        ->and(json_decode($paymentSource->response, true)['id'])->toBe('pm_card')
        ->and($this->stripe->sentOnce('POST', '/v1/payment_methods/pm_card/attach')['params'])->toBe(['customer' => 'cus_test'])
        // It’s the customer’s first payment source, so it becomes their default payment method in Stripe.
        ->and($this->stripe->sentOnce('POST', '/v1/customers/cus_test')['params'])->toBe(['invoice_settings' => ['default_payment_method' => 'pm_card']])
        ->and(Customer::query()->where('userId', $user->id)->value('reference'))->toBe('cus_test');
});

it('recreates Stripe customers that were deleted in Stripe', function() {
    $this->stripe
        ->respond('POST', '/v1/customers', ['id' => 'cus_new', 'object' => 'customer'])
        ->respond('GET', '/v1/customers/cus_deleted', ['id' => 'cus_deleted', 'object' => 'customer', 'deleted' => true])
        ->respond('GET', '/v1/customers/cus_new', ['id' => 'cus_new', 'object' => 'customer'])
        ->respond('POST', '/v1/payment_methods/pm_card/attach', cardPaymentMethod(['customer' => 'cus_new']))
        ->respond('POST', '/v1/customers/cus_new', ['id' => 'cus_new', 'object' => 'customer']);
    $gateway = stripeGateway();
    $user = stripeUser();
    Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_deleted']);

    app(PaymentSources::class)->createPaymentSource($user->id, $gateway, new PaymentIntentForm(['paymentMethodId' => 'pm_card']));

    expect($this->stripe->sentOnce('POST', '/v1/payment_methods/pm_card/attach')['params'])->toBe(['customer' => 'cus_new'])
        ->and(Customer::query()->where('userId', $user->id)->pluck('reference')->all())->toBe(['cus_new']);
});

it('detaches the payment method when a payment source is deleted, and picks a new primary one', function() {
    $this->stripe
        ->respond('POST', '/v1/payment_methods/pm_one/detach', cardPaymentMethod(['id' => 'pm_one']))
        ->default('POST', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer']);
    $gateway = stripeGateway();
    $user = stripeUser();
    Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_test']);
    $paymentSources = app(PaymentSources::class);
    foreach (['pm_one', 'pm_two'] as $token) {
        $paymentSources->savePaymentSource(new CraftCms\Commerce\Payment\Data\PaymentSource(['customerId' => $user->id, 'gatewayId' => $gateway->id, 'token' => $token, 'description' => $token, 'response' => '']));
    }
    $one = $paymentSources->getPaymentSourceByTokenAndGatewayId('pm_one', $gateway->id);
    $two = $paymentSources->getPaymentSourceByTokenAndGatewayId('pm_two', $gateway->id);
    app(CraftCms\Commerce\Customer\Customers::class)->savePrimaryPaymentSourceId($user, $one->id);

    expect($paymentSources->deletePaymentSourceById($one->id))->toBeTrue()
        ->and($this->stripe->sent('POST', '/v1/payment_methods/pm_one/detach'))->toHaveCount(1)
        ->and(app(CraftCms\Commerce\Customer\Customers::class)->ensureCustomer($user)->primaryPaymentSourceId)->toBe($two->id);
});

it('creates the plugin’s tables, and drops them when it’s uninstalled', function() {
    expect(Schema::hasTable('stripe_customers'))->toBeTrue()
        ->and(Schema::hasTable('stripe_invoices'))->toBeTrue()
        ->and(Schema::hasTable('stripe_paymentintents'))->toBeTrue();

    app(Plugins::class)->uninstallPlugin('commerce-stripe');

    expect(Schema::hasTable('stripe_customers'))->toBeFalse()
        ->and(Schema::hasTable('stripe_invoices'))->toBeFalse()
        ->and(Schema::hasTable('stripe_paymentintents'))->toBeFalse();
});
