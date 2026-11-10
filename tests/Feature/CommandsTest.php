<?php

declare(strict_types=1);

use CraftCms\Commerce\Payment\PaymentSources;
use CraftCms\CommerceStripe\Models\Customer;
use CraftCms\CommerceStripe\Models\PaymentIntent;

use function Pest\Laravel\artisan;

it('resets the plugin’s data', function() {
    $gateway = stripeGateway();
    $user = stripeUser();
    $transaction = savedTransaction(stripeTransaction($gateway), 'success', 'pi_test');
    $customer = Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_test']);
    PaymentIntent::query()->create(['reference' => 'pi_test', 'gatewayId' => $gateway->id, 'customerId' => $customer->id, 'transactionHash' => $transaction->hash, 'intentData' => '{}']);

    artisan('commerce-stripe:reset-data', ['--force' => true])
        ->expectsOutputToContain('Finished resetting Stripe plugin data.')
        ->assertSuccessful();

    expect(Customer::query()->count())->toBe(0)
        ->and(PaymentIntent::query()->count())->toBe(0);
});

it('asks before resetting the plugin’s data', function() {
    $gateway = stripeGateway();
    Customer::query()->create(['userId' => stripeUser()->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_test']);

    // Craft prefixes command aliases with `craft:`.
    artisan('craft:commerce-stripe/reset-data')
        ->expectsConfirmation('Resetting Stripe plugin data will permanently delete all customers, payment intents, and invoice records. Do you wish to continue?', 'no')
        ->assertSuccessful();

    expect(Customer::query()->count())->toBe(1);
});

it('syncs payment sources from Stripe', function() {
    $this->stripe
        ->respond('GET', '/v1/customers', ['object' => 'list', 'url' => '/v1/customers', 'has_more' => false, 'data' => [['id' => 'cus_test', 'object' => 'customer'], ['id' => 'cus_unknown', 'object' => 'customer']]])
        ->respond('GET', '/v1/customers/cus_test/payment_methods', ['object' => 'list', 'url' => '/v1/customers/cus_test/payment_methods', 'has_more' => false, 'data' => [cardPaymentMethod(['customer' => 'cus_test'])]])
        ->respond('GET', '/v1/customers/cus_unknown/payment_methods', ['object' => 'list', 'url' => '/v1/customers/cus_unknown/payment_methods', 'has_more' => false, 'data' => [cardPaymentMethod(['id' => 'pm_unknown', 'customer' => 'cus_unknown'])]])
        ->respond('GET', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer'])
        ->respond('POST', '/v1/payment_methods/pm_card/attach', cardPaymentMethod(['customer' => 'cus_test']))
        ->default('POST', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer']);
    $gateway = stripeGateway();
    $user = stripeUser();
    Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_test']);

    artisan('commerce-stripe:sync:payment-sources')
        ->expectsChoice('Which gateway would you like to sync payment sources for?', 'stripe', ['stripe' => 'Stripe'])
        ->expectsConfirmation('Do you want to continue?', 'yes')
        ->expectsOutputToContain('Synchronized 2 payment method(s).')
        ->assertSuccessful();

    $paymentSources = app(PaymentSources::class);
    expect($paymentSources->getPaymentSourceByTokenAndGatewayId('pm_card', $gateway->id)->customerId)->toBe($user->id)
        // Payment methods of customers that aren’t in Craft are skipped.
        ->and($paymentSources->getPaymentSourceByTokenAndGatewayId('pm_unknown', $gateway->id))->toBeNull();
});

it('makes the primary payment source the customer’s default payment method in Stripe', function() {
    $this->stripe->respond('POST', '/v1/customers/cus_test', ['id' => 'cus_test', 'object' => 'customer']);
    $gateway = stripeGateway();
    $user = stripeUser();
    Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_test']);
    $paymentSources = app(PaymentSources::class);
    $paymentSources->savePaymentSource(new CraftCms\Commerce\Payment\Data\PaymentSource(['customerId' => $user->id, 'gatewayId' => $gateway->id, 'token' => 'pm_card', 'description' => 'Visa', 'response' => '']));

    app(CraftCms\Commerce\Customer\Customers::class)->savePrimaryPaymentSourceId($user, $paymentSources->getPaymentSourceByTokenAndGatewayId('pm_card', $gateway->id)->id);

    expect($this->stripe->sentOnce('POST', '/v1/customers/cus_test')['params'])->toBe(['invoice_settings' => ['default_payment_method' => 'pm_card']]);
});
