<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceStripe\Gateway;
use CraftCms\CommerceStripe\Tests\TestCase;

uses(TestCase::class)->in('Feature');

const STRIPE_SECRET_KEY = 'sk_test_secret';
const STRIPE_SIGNING_SECRET = 'whsec_test_secret';

/**
 * Creates a Stripe gateway, and saves it unless `$save` is false.
 *
 * @param array<string, mixed> $settings
 */
function stripeGateway(array $settings = [], string $paymentType = 'purchase', bool $save = true): Gateway
{
    /** @var Gateway $gateway */
    $gateway = app(Gateways::class)->createGateway([
        'type' => Gateway::class,
        'name' => 'Stripe',
        'handle' => 'stripe',
        'paymentType' => $paymentType,
        'settings' => array_merge([
            'publishableKey' => 'pk_test_publishable',
            'apiKey' => STRIPE_SECRET_KEY,
            'signingSecret' => STRIPE_SIGNING_SECRET,
        ], $settings),
    ]);

    if ($save) {
        expect(app(Gateways::class)->saveGateway($gateway))->toBeTrue();
    }

    return $gateway;
}

function stripeUser(string $email = 'customer@example.com'): User
{
    $user = new User();
    $user->username = $email;
    $user->email = $email;
    $user->fullName = 'Jenny Andrews';
    expect(Elements::saveElement($user))->toBeTrue();

    return $user;
}

/**
 * Saves an order for the gateway and returns a new, unsaved transaction for it.
 */
function stripeTransaction(Gateway $gateway, float $amount = 12.34, string $type = TransactionModel::TYPE_PURCHASE, ?User $customer = null): Transaction
{
    $order = new Order();
    $order->email = $customer->email ?? 'guest@example.com';
    $order->gatewayId = $gateway->id;
    $order->cancelUrl = 'https://example.com/shop/checkout';
    if ($customer) {
        $order->setCustomer($customer);
    }
    expect(Elements::saveElement($order))->toBeTrue();

    $transaction = app(Transactions::class)->createTransaction($order, null, $type);
    $transaction->paymentAmount = $amount;
    $transaction->amount = $amount;

    return $transaction;
}

/**
 * Saves a transaction with a status and Stripe reference.
 */
function savedTransaction(Transaction $transaction, string $status, string $reference, mixed $response = null): Transaction
{
    $transaction->status = $status;
    $transaction->reference = $reference;
    $transaction->response = $response ?? ['id' => $reference, 'object' => 'payment_intent'];
    expect(app(Transactions::class)->saveTransaction($transaction))->toBeTrue();

    return app(Transactions::class)->getTransactionById($transaction->id);
}

/**
 * Returns a Stripe payment intent object.
 *
 * @param array<string, mixed> $attributes
 * @return array<string, mixed>
 */
function paymentIntent(array $attributes = []): array
{
    return array_merge([
        'id' => 'pi_test',
        'object' => 'payment_intent',
        'amount' => 1234,
        'currency' => 'usd',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_test_secret_abc',
        'next_action' => null,
        'last_payment_error' => null,
        'payment_method' => null,
        'metadata' => [],
    ], $attributes);
}

/**
 * Returns a Stripe card payment method object.
 *
 * @param array<string, mixed> $attributes
 * @return array<string, mixed>
 */
function cardPaymentMethod(array $attributes = []): array
{
    return array_merge([
        'id' => 'pm_card',
        'object' => 'payment_method',
        'type' => 'card',
        'customer' => null,
        'card' => ['brand' => 'visa', 'last4' => '4242'],
    ], $attributes);
}

/**
 * Returns the `Stripe-Signature` header for a payload, signed as Stripe documents it: an HMAC-SHA256 of
 * `{timestamp}.{payload}`, keyed with the endpoint’s signing secret.
 *
 * @see https://docs.stripe.com/webhooks#verify-manually
 */
function stripeSignature(string $payload, string $secret = STRIPE_SIGNING_SECRET, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return "t=$timestamp,v1=" . hash_hmac('sha256', "$timestamp.$payload", $secret);
}

/**
 * Returns a Stripe event payload.
 *
 * @param array<string, mixed> $object
 */
function stripeEvent(string $type, array $object): string
{
    return json_encode([
        'id' => 'evt_test',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $object],
    ]);
}
