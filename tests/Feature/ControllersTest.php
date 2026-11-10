<?php

declare(strict_types=1);

use CraftCms\Commerce\Payment\PaymentSources;
use CraftCms\CommerceStripe\Models\Customer;
use Illuminate\Support\Facades\Crypt;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

it('sets whether a payment intent saves its payment method, for guests too', function(bool $save, ?string $expected) {
    $this->stripe->respond('POST', '/v1/payment_intents/pi_test', paymentIntent());
    $gateway = stripeGateway();

    postJson('actions/commerce-stripe/payments/save-payment-intent', [
        'gatewayId' => $gateway->id,
        'paymentIntentId' => 'pi_test',
        'paymentIntent' => ['setup_future_usage' => $save],
    ])->assertOk()->assertJson(['success' => true]);

    expect($this->stripe->sentOnce('POST', '/v1/payment_intents/pi_test')['params'])->toBe(['setup_future_usage' => $expected]);
})->with([
    'save' => [true, 'off_session'],
    // The SDK sends an empty string to unset the param.
    'don’t save' => [false, ''],
]);

it('requires a payment intent ID to update a payment intent', function() {
    postJson('actions/commerce-stripe/payments/save-payment-intent', ['gatewayId' => stripeGateway()->id])
        ->assertBadRequest();

    expect($this->stripe->requests)->toBeEmpty();
});

it('requires a login to manage Stripe customers', function(string $method, string $action) {
    $gateway = stripeGateway();

    $response = $method === 'GET'
        ? get("actions/commerce-stripe/customers/$action?gatewayId=$gateway->id&setup_intent=seti_test", ['Accept' => 'application/json'])
        : postJson("actions/commerce-stripe/customers/$action", ['gatewayId' => $gateway->id]);

    $response->assertUnauthorized();
    expect($this->stripe->requests)->toBeEmpty();
})->with([
    ['POST', 'billing-portal-redirect'],
    ['POST', 'create-setup-intent'],
    ['GET', 'confirm-setup-intent'],
]);

it('creates a setup intent for the logged-in customer', function() {
    $this->stripe->respond('POST', '/v1/setup_intents', ['id' => 'seti_test', 'object' => 'setup_intent', 'client_secret' => 'seti_test_secret']);
    $gateway = stripeGateway();
    actingAs($user = stripeUser());

    postJson('actions/commerce-stripe/customers/create-setup-intent', ['gatewayId' => $gateway->id])
        ->assertOk()
        ->assertJson(['id' => 'seti_test', 'client_secret' => 'seti_test_secret']);

    expect($this->stripe->sentOnce('POST', '/v1/setup_intents')['params'])->toBe([
        'usage' => 'off_session',
        // The SDK encodes booleans as strings.
        'automatic_payment_methods' => ['enabled' => 'true'],
        'customer' => 'cus_test',
    ])
        ->and($this->stripe->sentOnce('POST', '/v1/customers')['params']['email'])->toBe($user->email);
});

it('redirects the logged-in customer to the billing portal', function() {
    $this->stripe->respond('POST', '/v1/billing_portal/sessions', ['id' => 'bps_test', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test']);
    stripeGateway();
    actingAs(stripeUser());

    $this->post('actions/commerce-stripe/customers/billing-portal-redirect', [
        'gatewayId' => 'stripe',
        'redirect' => Crypt::encrypt('account'),
    ])->assertRedirect('https://billing.stripe.com/p/session/test?customer_id=cus_test');

    expect($this->stripe->sentOnce('POST', '/v1/billing_portal/sessions')['params'])->toMatchArray(['customer' => 'cus_test'])
        ->and($this->stripe->sentOnce('POST', '/v1/billing_portal/sessions')['params']['return_url'])->toEndWith('/account');
});

it('saves the payment method of a confirmed setup intent as a payment source', function() {
    $this->stripe
        ->respond('GET', '/v1/setup_intents/seti_test', ['id' => 'seti_test', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_test', 'payment_method' => 'pm_card'])
        ->respond('GET', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer'])
        ->respond('POST', '/v1/payment_methods/pm_card/attach', cardPaymentMethod(['customer' => 'cus_test']))
        ->respond('POST', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer']);
    $gateway = stripeGateway();
    actingAs($user = stripeUser());
    Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_test']);

    get('actions/commerce-stripe/customers/confirm-setup-intent?' . http_build_query([
        'gatewayId' => $gateway->id,
        'setup_intent' => 'seti_test',
        'description' => 'My card',
        'redirect' => Crypt::encrypt('/account/cards'),
    ]))->assertRedirect('/account/cards');

    $paymentSource = app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId('pm_card', $gateway->id);
    expect($paymentSource->customerId)->toBe($user->id)
        ->and($paymentSource->description)->toBe('My card');
});

it('doesn’t save another customer’s setup intent', function() {
    $this->stripe->respond('GET', '/v1/setup_intents/seti_test', ['id' => 'seti_test', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_other', 'payment_method' => 'pm_card']);
    $gateway = stripeGateway();
    $other = stripeUser('other@example.com');
    Customer::query()->create(['userId' => $other->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_other']);
    actingAs(stripeUser());

    get("actions/commerce-stripe/customers/confirm-setup-intent?gatewayId=$gateway->id&setup_intent=seti_test")
        ->assertForbidden();

    expect(app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId('pm_card', $gateway->id))->toBeNull()
        ->and($this->stripe->sent('POST', '/v1/payment_methods/pm_card/attach'))->toBeEmpty();
});

it('only lets admins sync payment sources', function() {
    $gateway = stripeGateway();
    actingAs(stripeUser());

    postJson('actions/commerce-stripe/default/sync-payment-sources', ['gatewayId' => $gateway->id])
        ->assertForbidden();

    expect($this->stripe->requests)->toBeEmpty();
});

it('syncs payment sources for admins', function() {
    $this->stripe
        ->respond('GET', '/v1/customers', ['object' => 'list', 'url' => '/v1/customers', 'has_more' => false, 'data' => [['id' => 'cus_test', 'object' => 'customer']]])
        ->respond('GET', '/v1/customers/cus_test/payment_methods', ['object' => 'list', 'url' => '/v1/customers/cus_test/payment_methods', 'has_more' => false, 'data' => [cardPaymentMethod(['customer' => 'cus_test'])]])
        ->respond('GET', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer'])
        ->respond('POST', '/v1/payment_methods/pm_card/attach', cardPaymentMethod(['customer' => 'cus_test']))
        ->default('POST', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer']);
    $gateway = stripeGateway();
    $user = stripeUser();
    Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_test']);
    $admin = stripeUser('admin@example.com');
    $admin->admin = true;
    expect(CraftCms\Cms\Support\Facades\Elements::saveElement($admin))->toBeTrue();
    actingAs($admin);

    postJson('actions/commerce-stripe/default/sync-payment-sources', ['gatewayId' => $gateway->id])
        ->assertOk()
        ->assertJson(['message' => 'Synced 1 payment sources.']);

    expect(app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId('pm_card', $gateway->id)->customerId)->toBe($user->id);
});
