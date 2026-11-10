<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe;

use CraftCms\CommerceStripe\Models\PaymentIntent;
use Illuminate\Container\Attributes\Singleton;

/**
 * Reads and saves the payment intents stored in the `stripe_paymentintents` table.
 */
#[Singleton]
class PaymentIntents
{
    /**
     * Returns a payment intent by gateway, Stripe customer row ID and transaction hash.
     */
    public function getPaymentIntent(int $gatewayId, int $customerId, ?string $transactionHash = null): ?PaymentIntent
    {
        return PaymentIntent::query()
            ->where('gatewayId', $gatewayId)
            ->where('customerId', $customerId)
            ->where('transactionHash', $transactionHash)
            ->first();
    }

    /**
     * Returns a payment intent by its Stripe payment intent ID.
     */
    public function getPaymentIntentByReference(string $reference): ?PaymentIntent
    {
        return PaymentIntent::query()->where('reference', $reference)->first();
    }

    public function savePaymentIntent(PaymentIntent $paymentIntent): bool
    {
        return $paymentIntent->save();
    }
}
