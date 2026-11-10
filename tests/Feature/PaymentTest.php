<?php

declare(strict_types=1);

use CraftCms\Commerce\Payment\Forms\BasePaymentForm;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\CommerceStripe\Events\GatewayRequestBuilding;
use CraftCms\CommerceStripe\Events\PaymentIntentConfirming;
use CraftCms\CommerceStripe\Events\ServerSideConfirming;
use CraftCms\CommerceStripe\Forms\PaymentIntentForm;
use CraftCms\CommerceStripe\Gateway;
use CraftCms\CommerceStripe\Models\Customer;
use CraftCms\CommerceStripe\Responses\CheckoutSessionResponse;
use CraftCms\CommerceStripe\Responses\ServerConfirmedPaymentIntentResponse;
use Illuminate\Support\Facades\Event;

it('creates a payment intent in the minor unit, with the transaction hash as the idempotency key', function() {
    $this->stripe->respond('POST', '/v1/payment_intents', paymentIntent());
    $gateway = stripeGateway();
    $transaction = stripeTransaction($gateway, 12.34);

    $response = $gateway->purchase($transaction, new PaymentIntentForm());

    $request = $this->stripe->sentOnce('POST', '/v1/payment_intents');
    expect($request['headers']['authorization'])->toBe('Bearer ' . STRIPE_SECRET_KEY)
        ->and($request['headers']['stripe-version'])->toBe('2026-01-28.clover')
        ->and($request['headers']['idempotency-key'])->toBe($transaction->hash)
        ->and($request['params']['amount'])->toBe(1234)
        ->and($request['params']['currency'])->toBe('USD')
        ->and($request['params']['capture_method'])->toBe('automatic')
        ->and($request['params']['confirm'])->toBe('false')
        ->and($request['params']['automatic_payment_methods'])->toBe(['enabled' => 'true'])
        ->and($request['params']['expand'])->toBe(['payment_method'])
        ->and($request['params']['description'])->toBe('Order #' . $transaction->orderId)
        ->and($request['params']['metadata']['transaction_reference'])->toBe($transaction->hash)
        ->and($request['params']['metadata']['order_number'])->toBe((string)$transaction->getOrder()->number)
        ->and($request['params']['customer'])->toBe('cus_test')
        ->and($request['params'])->not->toHaveKeys(['payment_method', 'receipt_email', 'setup_future_usage']);

    // Stripe.js confirms the payment intent on the same page, with its client secret.
    expect($response->isRedirect())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getRedirectData())->toBe(['client_secret' => 'pi_test_secret_abc', 'payment_intent' => 'pi_test'])
        ->and($response->getTransactionReference())->toBe('pi_test');
});

it('uses manual capture when authorizing', function() {
    $this->stripe->respond('POST', '/v1/payment_intents', paymentIntent());
    $gateway = stripeGateway(paymentType: 'authorize');
    $transaction = stripeTransaction($gateway, 10, TransactionModel::TYPE_AUTHORIZE);

    $gateway->authorize($transaction, new PaymentIntentForm());

    expect($this->stripe->sentOnce('POST', '/v1/payment_intents')['params']['capture_method'])->toBe('manual');
});

it('sends amounts in zero-decimal currencies as they are', function() {
    $this->stripe->respond('POST', '/v1/payment_intents', paymentIntent());
    $gateway = stripeGateway();
    $transaction = stripeTransaction($gateway, 1234);
    $transaction->paymentCurrency = 'JPY';

    $gateway->purchase($transaction, new PaymentIntentForm());

    $params = $this->stripe->sentOnce('POST', '/v1/payment_intents')['params'];
    expect($params['amount'])->toBe(1234)
        ->and($params['currency'])->toBe('JPY');
});

it('creates a Stripe customer for the order’s customer, and sends the receipt email', function() {
    $this->stripe
        ->respond('POST', '/v1/customers', ['id' => 'cus_new', 'object' => 'customer'])
        ->respond('POST', '/v1/payment_intents', paymentIntent());
    $gateway = stripeGateway(['sendReceiptEmail' => true]);
    $user = stripeUser();
    $transaction = stripeTransaction($gateway, customer: $user);

    $gateway->purchase($transaction, new PaymentIntentForm(['savePaymentSource' => true]));

    $customerRequest = $this->stripe->sentOnce('POST', '/v1/customers')['params'];
    expect($customerRequest['email'])->toBe('customer@example.com')
        ->and($customerRequest['name'])->toBe('Jenny Andrews')
        ->and($customerRequest['metadata'])->toBe(['craft_user_id' => $user->id]);

    $params = $this->stripe->sentOnce('POST', '/v1/payment_intents')['params'];
    expect($params['customer'])->toBe('cus_new')
        ->and($params['setup_future_usage'])->toBe('off_session')
        ->and($params['receipt_email'])->toBe('customer@example.com')
        ->and(Customer::query()->where('userId', $user->id)->value('reference'))->toBe('cus_new');

    // The customer is only created once.
    $gateway->purchase(stripeTransaction($gateway, customer: $user), new PaymentIntentForm());
    expect($this->stripe->sent('POST', '/v1/customers'))->toHaveCount(1);
});

it('lets listeners change the payment intent request, except the amount and currency', function() {
    $this->stripe->respond('POST', '/v1/payment_intents', paymentIntent());
    Event::listen(function(GatewayRequestBuilding $event) {
        expect($event->type)->toBe('payment_intent');
        $event->request['metadata']['shipping_method'] = 'ups';
        $event->request['amount'] = 1;
        $event->request['currency'] = 'EUR';
    });
    $gateway = stripeGateway();

    $gateway->purchase(stripeTransaction($gateway, 12.34), new PaymentIntentForm());

    $params = $this->stripe->sentOnce('POST', '/v1/payment_intents')['params'];
    expect($params['metadata']['shipping_method'])->toBe('ups')
        ->and($params['amount'])->toBe(1234)
        ->and($params['currency'])->toBe('USD');
});

it('confirms a payment intent straight away when paying with a payment method', function() {
    $this->stripe
        ->respond('GET', '/v1/payment_methods/pm_card', cardPaymentMethod())
        ->respond('POST', '/v1/payment_intents', paymentIntent(['status' => 'requires_confirmation', 'payment_method' => 'pm_card']))
        ->respond('POST', '/v1/payment_intents/pi_test/confirm', paymentIntent(['status' => 'succeeded', 'payment_method' => cardPaymentMethod()]));
    Event::listen(fn(PaymentIntentConfirming $event) => $event->parameters['receipt_email'] = 'listener@example.com');
    $gateway = stripeGateway();
    $transaction = stripeTransaction($gateway);

    $response = $gateway->purchase($transaction, new PaymentIntentForm(['paymentMethodId' => 'pm_card']));

    expect($this->stripe->sentOnce('POST', '/v1/payment_intents')['params']['payment_method'])->toBe('pm_card');
    $confirm = $this->stripe->sentOnce('POST', '/v1/payment_intents/pi_test/confirm')['params'];
    expect($confirm['return_url'])->toContain('commerce/payments/complete-payment')
        ->toContain('commerceTransactionHash=' . $transaction->hash)
        ->and($confirm['receipt_email'])->toBe('listener@example.com')
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->isRedirect())->toBeFalse();
});

it('leaves PayPal payment intents for Stripe.js to confirm', function() {
    $this->stripe
        ->respond('GET', '/v1/payment_methods/pm_paypal', ['id' => 'pm_paypal', 'object' => 'payment_method', 'type' => 'paypal'])
        ->respond('POST', '/v1/payment_intents', paymentIntent(['status' => 'requires_confirmation']));
    $gateway = stripeGateway();

    $response = $gateway->purchase(stripeTransaction($gateway), new PaymentIntentForm(['paymentMethodId' => 'pm_paypal']));

    expect($this->stripe->sent('POST', '/v1/payment_intents/pi_test/confirm'))->toBeEmpty()
        ->and($response->isRedirect())->toBeTrue()
        ->and($response->getRedirectData()['client_secret'])->toBe('pi_test_secret_abc');
});

it('reports declined cards as failed payments', function() {
    $this->stripe
        ->respond('GET', '/v1/payment_methods/pm_card', cardPaymentMethod())
        ->respond('POST', '/v1/payment_intents', paymentIntent(['status' => 'requires_confirmation']))
        ->respond('POST', '/v1/payment_intents/pi_test/confirm', paymentIntent([
            'status' => 'requires_payment_method',
            'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
        ]));
    $gateway = stripeGateway();

    $response = $gateway->purchase(stripeTransaction($gateway), new PaymentIntentForm(['paymentMethodId' => 'pm_card']));

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getCode())->toBe('card_declined')
        ->and($response->getMessage())->toBe('Your card was declined.');
});

it('creates and confirms the payment intent with a confirmation token', function() {
    $this->stripe->respond('POST', '/v1/payment_intents', paymentIntent([
        'status' => 'requires_action',
        'next_action' => ['type' => 'use_stripe_sdk'],
    ]));
    $gateway = stripeGateway();
    $transaction = stripeTransaction($gateway, 50);

    $response = $gateway->purchase($transaction, new PaymentIntentForm(['confirmationToken' => 'ctoken_abc']));

    $params = $this->stripe->sentOnce('POST', '/v1/payment_intents')['params'];
    expect($params['confirm'])->toBe('true')
        ->and($params['confirmation_token'])->toBe('ctoken_abc')
        ->and($params['use_stripe_sdk'])->toBe('true')
        ->and($params['amount'])->toBe(5000)
        ->and($params['return_url'])->toContain('commerceTransactionHash=' . $transaction->hash)
        // The ConfirmationToken is only retrieved when something listens for it.
        ->and($this->stripe->sent('GET', '/v1/confirmation_tokens/ctoken_abc'))->toBeEmpty();

    expect($response)->toBeInstanceOf(ServerConfirmedPaymentIntentResponse::class)
        ->and($response->isRedirect())->toBeTrue()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getRedirectData())->toMatchArray([
            'client_secret' => 'pi_test_secret_abc',
            'status' => 'requires_action',
        ])
        ->and($response->getRedirectData()['complete_payment_url'])->toContain('commerceTransactionHash=' . $transaction->hash);
});

it('completes server-confirmed payments that need no further action', function() {
    $this->stripe->respond('POST', '/v1/payment_intents', paymentIntent(['status' => 'succeeded']));
    $gateway = stripeGateway();

    $response = $gateway->purchase(stripeTransaction($gateway), new PaymentIntentForm(['confirmationToken' => 'ctoken_abc']));

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->getRedirectData())->toBe([]);
});

it('rejects confirmation tokens that aren’t Stripe ConfirmationToken IDs', function() {
    $gateway = stripeGateway();

    $response = $gateway->purchase(stripeTransaction($gateway), new PaymentIntentForm(['confirmationToken' => 'pm_card']));

    expect($this->stripe->requests)->toBeEmpty()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->getCode())->toBe('invalid_confirmation_token')
        ->and($response->getMessage())->toBe('Invalid confirmation token.');
});

it('lets listeners reject a server-side confirmation', function() {
    $this->stripe->respond('GET', '/v1/confirmation_tokens/ctoken_abc', [
        'id' => 'ctoken_abc',
        'object' => 'confirmation_token',
        'payment_method_preview' => ['type' => 'card', 'card' => ['funding' => 'prepaid']],
    ]);
    Event::listen(function(ServerSideConfirming $event) {
        if ($event->confirmationToken->payment_method_preview->card->funding === 'prepaid') {
            $event->isValid = false;
            $event->errorMessage = 'Prepaid cards are not accepted.';
        }
    });
    $gateway = stripeGateway();

    $response = $gateway->purchase(stripeTransaction($gateway), new PaymentIntentForm(['confirmationToken' => 'ctoken_abc']));

    expect($this->stripe->sent('POST', '/v1/payment_intents'))->toBeEmpty()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getCode())->toBe('payment_rejected')
        ->and($response->getMessage())->toBe('Prepaid cards are not accepted.');
});

it('reports Stripe API errors from server-side confirmation', function() {
    $this->stripe->respond('POST', '/v1/payment_intents', [
        'error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card has insufficient funds.', 'charge' => 'ch_declined'],
    ], 402);
    $gateway = stripeGateway();

    $response = $gateway->purchase(stripeTransaction($gateway), new PaymentIntentForm(['confirmationToken' => 'ctoken_abc']));

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->getCode())->toBe('card_declined')
        ->and($response->getMessage())->toBe('Your card has insufficient funds.')
        ->and($response->getTransactionReference())->toBe('ch_declined');
});

it('creates a Checkout session and redirects to it', function() {
    $this->stripe->respond('POST', '/v1/checkout/sessions', [
        'id' => 'cs_test',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test',
    ]);
    Event::listen(fn(GatewayRequestBuilding $event) => $event->request['metadata']['type'] = $event->type);
    $gateway = stripeGateway();
    $transaction = stripeTransaction($gateway, 99.99);

    $response = $gateway->purchase($transaction, new PaymentIntentForm(['paymentFormType' => 'checkout']));

    $params = $this->stripe->sentOnce('POST', '/v1/checkout/sessions')['params'];
    expect($params['mode'])->toBe('payment')
        ->and($params['client_reference_id'])->toBe($transaction->hash)
        ->and($params['cancel_url'])->toBe('https://example.com/shop/checkout')
        ->and($params['success_url'])->toContain('commerceTransactionHash=' . $transaction->hash)
        ->and($params['customer'])->toBe('cus_test')
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe(9999)
        ->and($params['line_items'][0]['price_data']['currency'])->toBe('USD')
        ->and($params['line_items'][0]['quantity'])->toBe(1)
        ->and($params['payment_intent_data'])->toBe(['capture_method' => 'automatic'])
        ->and($params['metadata']['transaction_reference'])->toBe($transaction->hash)
        ->and($params['metadata']['type'])->toBe('checkout.session');

    expect($response)->toBeInstanceOf(CheckoutSessionResponse::class)
        ->and($response->isRedirect())->toBeTrue()
        ->and($response->getRedirectMethod())->toBe('GET')
        ->and($response->getRedirectUrl())->toBe('https://checkout.stripe.com/c/pay/cs_test')
        ->and($response->getTransactionReference())->toBe('cs_test');
});

it('completes a payment from its payment intent', function(array $intent, bool $successful, bool $processing, bool $redirect) {
    $this->stripe->respond('GET', '/v1/payment_intents/pi_test', paymentIntent($intent));
    $gateway = stripeGateway();
    $transaction = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_REDIRECT, 'pi_test');

    $response = $gateway->completePurchase($transaction);

    expect($this->stripe->sentOnce('GET', '/v1/payment_intents/pi_test')['params'])->toBe(['expand' => ['payment_method']])
        ->and($response->isSuccessful())->toBe($successful)
        ->and($response->isProcessing())->toBe($processing)
        ->and($response->isRedirect())->toBe($redirect);
})->with([
    'succeeded' => [['status' => 'succeeded'], true, false, false],
    'authorized' => [['status' => 'requires_capture'], true, false, false],
    'processing' => [['status' => 'processing'], false, true, false],
    'bank transfer instructions' => [['status' => 'requires_action', 'next_action' => ['type' => 'display_bank_transfer_instructions']], false, true, false],
    'ACH micro-deposits' => [['status' => 'requires_action', 'next_action' => ['type' => 'verify_with_microdeposits']], false, true, false],
    '3D Secure redirect' => [['status' => 'requires_action', 'next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3ds']]], false, false, true],
    'failed' => [['status' => 'requires_payment_method', 'last_payment_error' => ['code' => 'payment_intent_authentication_failure', 'message' => 'x']], false, false, false],
    'canceled' => [['status' => 'canceled'], false, false, false],
]);

it('completes a Checkout session payment from the session’s payment intent', function() {
    $this->stripe
        ->respond('GET', '/v1/checkout/sessions/cs_test', ['id' => 'cs_test', 'object' => 'checkout.session', 'payment_intent' => 'pi_test'])
        ->respond('GET', '/v1/payment_intents/pi_test', paymentIntent(['status' => 'succeeded']));
    $gateway = stripeGateway();
    $transaction = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_REDIRECT, 'cs_test', ['id' => 'cs_test', 'object' => 'checkout.session']);

    $response = $gateway->completePurchase($transaction);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('pi_test');
});

it('explains a failed 3D Secure authentication', function() {
    $this->stripe->respond('GET', '/v1/payment_intents/pi_test', paymentIntent([
        'last_payment_error' => ['code' => 'payment_intent_authentication_failure', 'message' => 'Stripe message'],
    ]));
    $gateway = stripeGateway();
    $transaction = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_REDIRECT, 'pi_test');

    expect($gateway->completePurchase($transaction)->getMessage())->toBe('The provided payment method has failed authentication.');
});

it('captures an authorized payment intent', function() {
    $this->stripe->respond('POST', '/v1/payment_intents/pi_test/capture', paymentIntent(['status' => 'succeeded']));
    $gateway = stripeGateway(paymentType: 'authorize');
    $transaction = stripeTransaction($gateway, 10, TransactionModel::TYPE_CAPTURE);

    $response = $gateway->capture($transaction, 'pi_test');

    expect($this->stripe->sentOnce('POST', '/v1/payment_intents/pi_test/capture')['headers']['idempotency-key'])->toBe('pi_test')
        ->and($response->isSuccessful())->toBeTrue();
});

it('reports capture errors', function() {
    $this->stripe->respond('POST', '/v1/payment_intents/pi_test/capture', [
        'error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'This PaymentIntent could not be captured.'],
    ], 400);
    $gateway = stripeGateway(paymentType: 'authorize');

    $response = $gateway->capture(stripeTransaction($gateway), 'pi_test');

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getCode())->toBe('payment_intent_unexpected_state')
        ->and($response->getMessage())->toBe('This PaymentIntent could not be captured.');
});

it('refunds part of a payment intent', function(array $refund, bool $successful, bool $processing) {
    $this->stripe
        ->respond('GET', '/v1/payment_intents/pi_test', paymentIntent(['status' => 'succeeded']))
        ->respond('POST', '/v1/refunds', array_merge(['id' => 're_test', 'object' => 'refund'], $refund));
    $gateway = stripeGateway();
    $refundTransaction = stripeTransaction($gateway, 5.05, TransactionModel::TYPE_REFUND);
    $refundTransaction->reference = 'pi_test';

    $response = $gateway->refund($refundTransaction);

    expect($this->stripe->sentOnce('POST', '/v1/refunds')['params'])->toBe(['payment_intent' => 'pi_test', 'amount' => 505])
        ->and($response->isSuccessful())->toBe($successful)
        ->and($response->isProcessing())->toBe($processing)
        ->and($response->getTransactionReference())->toBe('re_test');
})->with([
    'succeeded' => [['status' => 'succeeded'], true, false],
    'pending' => [['status' => 'pending'], false, true],
]);

it('refunds payments made with the old Charge gateway', function() {
    $this->stripe->respond('POST', '/v1/refunds', ['id' => 're_test', 'object' => 'refund', 'status' => 'succeeded']);
    $gateway = stripeGateway();
    $refundTransaction = stripeTransaction($gateway, 20, TransactionModel::TYPE_REFUND);
    $refundTransaction->reference = 'ch_old';

    $response = $gateway->refund($refundTransaction);

    expect($this->stripe->sentOnce('POST', '/v1/refunds')['params'])->toBe(['charge' => 'ch_old', 'amount' => 2000])
        ->and($this->stripe->sent('GET', '/v1/payment_intents/ch_old'))->toBeEmpty()
        ->and($response->isSuccessful())->toBeTrue();
});

it('doesn’t refund payment intents that haven’t succeeded', function() {
    $this->stripe->respond('GET', '/v1/payment_intents/pi_test', paymentIntent(['status' => 'requires_capture']));
    $gateway = stripeGateway();
    $refundTransaction = stripeTransaction($gateway, 5, TransactionModel::TYPE_REFUND);
    $refundTransaction->reference = 'pi_test';

    $response = $gateway->refund($refundTransaction);

    expect($this->stripe->sent('POST', '/v1/refunds'))->toBeEmpty()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Unable to refund payment intent.');
});

it('doesn’t allow refunds of payments funded by a customer’s cash balance', function() {
    $gateway = stripeGateway();
    $cashBalance = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_SUCCESS, 'pi_test', [
        'object' => 'customer_cash_balance_transaction',
        'type' => 'applied_to_payment',
    ]);
    $card = savedTransaction(stripeTransaction($gateway), TransactionModel::STATUS_SUCCESS, 'pi_card');

    expect($gateway->transactionSupportsRefund($cashBalance))->toBeFalse()
        ->and($gateway->transactionSupportsRefund($card))->toBeTrue();
});

it('declares what the gateway supports', function() {
    $gateway = stripeGateway(save: false);

    expect($gateway->supportsAuthorize())->toBeTrue()
        ->and($gateway->supportsCapture())->toBeTrue()
        ->and($gateway->supportsPurchase())->toBeTrue()
        ->and($gateway->supportsCompletePurchase())->toBeTrue()
        ->and($gateway->supportsCompleteAuthorize())->toBeTrue()
        ->and($gateway->supportsRefund())->toBeTrue()
        ->and($gateway->supportsPartialRefund())->toBeTrue()
        ->and($gateway->supportsPaymentSources())->toBeTrue()
        ->and($gateway->supportsWebhooks())->toBeTrue()
        ->and($gateway->showPaymentFormSubmitButton())->toBeFalse()
        ->and($gateway->cpPaymentsEnabled())->toBeTrue()
        ->and($gateway->getPaymentFormModel())->toBeInstanceOf(PaymentIntentForm::class)
        ->and($gateway->getPaymentFormModel())->toBeInstanceOf(BasePaymentForm::class)
        ->and(Gateway::displayName())->toBe('Stripe');
});
