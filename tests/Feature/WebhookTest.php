<?php

declare(strict_types=1);

use CraftCms\Commerce\Customer\Customers as CommerceCustomers;
use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\PaymentSources;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceStripe\Events\WebhookReceived;
use CraftCms\CommerceStripe\Gateway;
use CraftCms\CommerceStripe\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Posts a webhook to the gateway’s webhook URL.
 */
function postWebhook(Gateway $gateway, string $payload, ?string $signature = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== '') {
        $server['HTTP_STRIPE_SIGNATURE'] = $signature ?? stripeSignature($payload);
    }

    return test()->call('POST', $gateway->getWebhookUrl(), server: $server, content: $payload);
}

function emptyList(): array
{
    return ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/checkout/sessions'];
}

it('verifies the webhook signature', function(string $case, int $status, string $body) {
    $received = [];
    Event::listen(function(WebhookReceived $event) use (&$received) {
        $received[] = $event->webhookData;
    });
    $gateway = stripeGateway();
    $payload = stripeEvent('charge.dispute.created', ['id' => 'dp_test', 'object' => 'dispute']);

    $signature = match ($case) {
        'valid' => stripeSignature($payload),
        'missing' => '',
        'other secret' => stripeSignature($payload, 'whsec_other'),
        // Stripe’s default tolerance is 5 minutes.
        'too old' => stripeSignature($payload, timestamp: time() - 301),
        'other payload' => stripeSignature(stripeEvent('charge.dispute.created', ['id' => 'dp_other'])),
    };

    $response = postWebhook($gateway, $payload, $signature);

    $response->assertStatus($status);
    expect($response->getContent())->toBe($body)
        ->and($received)->toHaveCount($status === 200 ? 1 : 0);

    if ($status === 200) {
        expect($received[0]['type'])->toBe('charge.dispute.created');
    }
})->with([
    'valid' => ['valid', 200, 'ok'],
    'missing' => ['missing', 403, 'Webhook not signed or signing secret not set.'],
    'signed with another secret' => ['other secret', 403, 'Webhook signature check failed.'],
    'too old' => ['too old', 403, 'Webhook signature check failed.'],
    'for another payload' => ['other payload', 403, 'Webhook signature check failed.'],
]);

it('refuses webhooks when no signing secret is set', function() {
    $gateway = stripeGateway(['signingSecret' => '']);
    $payload = stripeEvent('charge.dispute.created', ['id' => 'dp_test']);

    postWebhook($gateway, $payload)->assertStatus(403);
});

it('reads the signing secret from an environment variable', function() {
    $_SERVER['STRIPE_SIGNING_SECRET'] = STRIPE_SIGNING_SECRET;
    $gateway = stripeGateway(['signingSecret' => '$STRIPE_SIGNING_SECRET']);

    postWebhook($gateway, stripeEvent('charge.dispute.created', ['id' => 'dp_test']))->assertOk();

    unset($_SERVER['STRIPE_SIGNING_SECRET']);
});

it('locks webhooks by the transaction hash, or the ID of the Stripe object', function(array $object, ?string $expected) {
    $gateway = stripeGateway(save: false);
    app()->instance('request', Request::create('/', 'POST', content: stripeEvent('some.event', $object)));

    expect($gateway->getTransactionHashFromWebhook())->toBe($expected);
})->with([
    'payment intent metadata' => [['id' => 'pi_test', 'metadata' => ['transaction_reference' => 'abc123']], 'abc123'],
    'refund' => [['id' => 're_test', 'payment_intent' => 'pi_test', 'metadata' => []], 'pi_test'],
    'other object' => [['id' => 'cus_test'], 'cus_test'],
    'no object ID' => [[], null],
]);

it('marks a redirected payment as successful when its payment intent succeeds', function() {
    $this->stripe
        ->respond('GET', '/v1/checkout/sessions', emptyList())
        ->respond('GET', '/v1/payment_methods/pm_card', cardPaymentMethod());
    $gateway = stripeGateway();
    $transaction = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_REDIRECT, 'pi_test');
    $payload = stripeEvent('payment_intent.succeeded', paymentIntent([
        'status' => 'succeeded',
        'payment_method' => 'pm_card',
        'metadata' => ['transaction_reference' => $transaction->hash],
    ]));

    postWebhook($gateway, $payload)->assertOk();

    expect($this->stripe->sentOnce('GET', '/v1/checkout/sessions')['params'])->toBe(['payment_intent' => 'pi_test']);
    $updated = app(Transactions::class)->getTransactionById($transaction->id);
    $response = json_decode($updated->response, true);
    expect($updated->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($updated->message)->toBeEmpty()
        // The payment method is expanded, like the responses stored when paying.
        ->and($response['payment_method']['card']['last4'])->toBe('4242');

    // A second delivery of the same event changes nothing.
    postWebhook($gateway, $payload)->assertOk();
    expect($this->stripe->sent('GET', '/v1/payment_methods/pm_card'))->toHaveCount(1)
        ->and(app(Transactions::class)->getChildrenByTransactionId($transaction->id))->toBeEmpty();
});

it('updates the processing child of a payment when its payment intent succeeds', function() {
    $this->stripe->respond('GET', '/v1/checkout/sessions', emptyList());
    $gateway = stripeGateway();
    $parent = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_REDIRECT, 'pi_test');
    $child = app(Transactions::class)->createTransaction(null, $parent);
    $child = savedTransaction($child, TransactionModel::STATUS_PROCESSING, 'pi_test');

    postWebhook($gateway, stripeEvent('payment_intent.succeeded', paymentIntent(['status' => 'succeeded'])))->assertOk();

    expect(app(Transactions::class)->getTransactionById($child->id)->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and(app(Transactions::class)->getTransactionById($parent->id)->status)->toBe(TransactionModel::STATUS_REDIRECT);
});

it('ignores successful payment intents that already have a successful transaction', function() {
    $this->stripe->respond('GET', '/v1/checkout/sessions', emptyList());
    $gateway = stripeGateway();
    $parent = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_REDIRECT, 'pi_test');
    $success = savedTransaction(app(Transactions::class)->createTransaction(null, $parent), TransactionModel::STATUS_SUCCESS, 'pi_test');
    $processing = savedTransaction(app(Transactions::class)->createTransaction(null, $parent), TransactionModel::STATUS_PROCESSING, 'pi_test');

    postWebhook($gateway, stripeEvent('payment_intent.succeeded', paymentIntent(['status' => 'succeeded'])))->assertOk();

    expect(app(Transactions::class)->getTransactionById($processing->id)->status)->toBe(TransactionModel::STATUS_PROCESSING)
        ->and(app(Transactions::class)->getTransactionById($success->id)->status)->toBe(TransactionModel::STATUS_SUCCESS);
});

it('ignores payment intents that don’t belong to a transaction', function() {
    $this->stripe->respond('GET', '/v1/checkout/sessions', emptyList());
    $gateway = stripeGateway();

    postWebhook($gateway, stripeEvent('payment_intent.succeeded', paymentIntent(['id' => 'pi_unknown', 'status' => 'succeeded'])))
        ->assertOk()
        ->assertContent('ok');
});

it('completes a Checkout session’s payment when its payment intent succeeds', function() {
    $this->stripe
        ->respond('GET', '/v1/checkout/sessions', ['object' => 'list', 'data' => [['id' => 'cs_test', 'object' => 'checkout.session', 'payment_intent' => 'pi_test']], 'has_more' => false, 'url' => '/v1/checkout/sessions'])
        ->respond('GET', '/v1/checkout/sessions/cs_test', ['id' => 'cs_test', 'object' => 'checkout.session', 'payment_intent' => 'pi_test'])
        ->respond('GET', '/v1/payment_intents/pi_test', paymentIntent(['status' => 'succeeded']));
    $gateway = stripeGateway();
    $transaction = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_REDIRECT, 'cs_test', ['id' => 'cs_test', 'object' => 'checkout.session']);

    // Checkout sessions keep their metadata to themselves, so the payment intent has none.
    postWebhook($gateway, stripeEvent('payment_intent.succeeded', paymentIntent(['status' => 'succeeded'])))->assertOk();

    $children = app(Transactions::class)->getChildrenByTransactionId($transaction->id);
    expect($children)->toHaveCount(1)
        ->and($children[0]->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($children[0]->reference)->toBe('pi_test');
});

it('marks failed payments as processing when they need an asynchronous action', function(string $nextAction, string $expected) {
    $gateway = stripeGateway();
    $transaction = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_FAILED, 'pi_test');

    postWebhook($gateway, stripeEvent('payment_intent.requires_action', paymentIntent([
        'status' => 'requires_action',
        'next_action' => ['type' => $nextAction],
    ])))->assertOk();

    expect(app(Transactions::class)->getTransactionById($transaction->id)->status)->toBe($expected);
})->with([
    'ACH micro-deposits' => ['verify_with_microdeposits', TransactionModel::STATUS_PROCESSING],
    'Klarna redirect' => ['redirect_to_url', TransactionModel::STATUS_FAILED],
]);

it('leaves redirected payments alone when their payment intent requires action', function() {
    $gateway = stripeGateway();
    $transaction = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_REDIRECT, 'pi_test');

    postWebhook($gateway, stripeEvent('payment_intent.requires_action', paymentIntent([
        'status' => 'requires_action',
        'next_action' => ['type' => 'verify_with_microdeposits'],
    ])))->assertOk();

    expect(app(Transactions::class)->getTransactionById($transaction->id)->status)->toBe(TransactionModel::STATUS_REDIRECT);
});

it('updates refund transactions from the refund’s status', function(array $refund, string $status, string $message) {
    $gateway = stripeGateway();
    $refundTransaction = savedTransaction(stripeTransaction($gateway, 5, TransactionModel::TYPE_REFUND), TransactionModel::STATUS_PROCESSING, 're_test');

    postWebhook($gateway, stripeEvent('refund.updated', array_merge(['id' => 're_test', 'object' => 'refund', 'payment_intent' => 'pi_test'], $refund)))->assertOk();

    $updated = app(Transactions::class)->getTransactionById($refundTransaction->id);
    expect($updated->status)->toBe($status)
        ->and((string)$updated->message)->toBe($message)
        ->and(json_decode($updated->response, true)['object']['id'])->toBe('re_test');
})->with([
    'succeeded' => [['status' => 'succeeded'], TransactionModel::STATUS_SUCCESS, ''],
    'pending' => [['status' => 'pending', 'pending_reason' => 'processing'], TransactionModel::STATUS_PROCESSING, 'processing'],
    'requires action' => [['status' => 'requires_action'], TransactionModel::STATUS_PROCESSING, 'Requires action, see next action data in response.'],
    'failed' => [['status' => 'failed', 'failure_reason' => 'expired_or_canceled_card'], TransactionModel::STATUS_FAILED, 'expired_or_canceled_card'],
    'canceled' => [['status' => 'canceled'], TransactionModel::STATUS_FAILED, 'Cancelled'],
]);

it('records payments funded by a customer’s cash balance', function() {
    $gateway = stripeGateway();
    $parent = savedTransaction(stripeTransaction($gateway, 12.34), TransactionModel::STATUS_PROCESSING, 'pi_test');
    $child = savedTransaction(app(Transactions::class)->createTransaction(null, $parent), TransactionModel::STATUS_PROCESSING, 'pi_test');

    postWebhook($gateway, stripeEvent('customer_cash_balance_transaction.created', [
        'id' => 'ccsbtxn_test',
        'object' => 'customer_cash_balance_transaction',
        'type' => 'applied_to_payment',
        'currency' => 'usd',
        'net_amount' => -1234,
        'applied_to_payment' => ['payment_intent' => 'pi_test'],
    ]))->assertOk();

    $children = app(Transactions::class)->getChildrenByTransactionId($child->id);
    expect($children)->toHaveCount(1)
        ->and($children[0]->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($children[0]->paymentAmount)->toBe(12.34)
        ->and($children[0]->amount)->toBe(12.34)
        ->and($gateway->transactionSupportsRefund($children[0]))->toBeFalse();
});

it('creates a payment source when a payment method is attached to a known customer', function() {
    $this->stripe
        ->respond('GET', '/v1/customers/cus_known', ['id' => 'cus_known', 'object' => 'customer'])
        ->respond('POST', '/v1/payment_methods/pm_card/attach', cardPaymentMethod(['customer' => 'cus_known']))
        ->respond('POST', '/v1/customers/cus_known', ['id' => 'cus_known', 'object' => 'customer']);
    $gateway = stripeGateway();
    $user = stripeUser();
    Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_known']);

    postWebhook($gateway, stripeEvent('payment_method.attached', cardPaymentMethod(['customer' => 'cus_known'])))->assertOk();

    $paymentSource = app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId('pm_card', $gateway->id);
    expect($paymentSource)->not->toBeNull()
        ->and($paymentSource->customerId)->toBe($user->id)
        ->and($paymentSource->description)->toBe('Visa ending in ••••4242')
        ->and($paymentSource->getIsPrimary())->toBeTrue()
        ->and($this->stripe->sentOnce('POST', '/v1/payment_methods/pm_card/attach')['params'])->toBe(['customer' => 'cus_known'])
        // The customer’s default payment method is set when the payment source is made primary.
        ->and($this->stripe->sent('POST', '/v1/customers/cus_known'))->not->toBeEmpty()
        ->each(fn($request) => $request->params->toBe(['invoice_settings' => ['default_payment_method' => 'pm_card']]));
});

it('ignores payment methods of unknown customers', function() {
    $gateway = stripeGateway();

    postWebhook($gateway, stripeEvent('payment_method.attached', cardPaymentMethod(['customer' => 'cus_unknown'])))->assertOk();

    expect($this->stripe->requests)->toBeEmpty()
        ->and(app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId('pm_card', $gateway->id))->toBeNull();
});

it('deletes the payment source of a detached payment method', function() {
    $gateway = stripeGateway();
    $user = stripeUser();
    $paymentSource = new PaymentSource(['customerId' => $user->id, 'gatewayId' => $gateway->id, 'token' => 'pm_card', 'description' => 'Visa', 'response' => '']);
    expect(app(PaymentSources::class)->savePaymentSource($paymentSource))->toBeTrue();

    postWebhook($gateway, stripeEvent('payment_method.detached', cardPaymentMethod()))->assertOk();

    expect(app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId('pm_card', $gateway->id))->toBeNull();
});

it('makes the customer’s default payment method in Stripe their primary payment source', function() {
    $gateway = stripeGateway();
    $user = stripeUser();
    Customer::query()->create(['userId' => $user->id, 'gatewayId' => $gateway->id, 'reference' => 'cus_known']);
    foreach (['pm_one', 'pm_two'] as $token) {
        app(PaymentSources::class)->savePaymentSource(new PaymentSource(['customerId' => $user->id, 'gatewayId' => $gateway->id, 'token' => $token, 'description' => $token, 'response' => '']));
    }
    $two = app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId('pm_two', $gateway->id);

    postWebhook($gateway, stripeEvent('customer.updated', [
        'id' => 'cus_known',
        'object' => 'customer',
        'invoice_settings' => ['default_payment_method' => 'pm_two'],
    ]))->assertOk();

    expect(app(CommerceCustomers::class)->ensureCustomer($user)->primaryPaymentSourceId)->toBe($two->id);
});
