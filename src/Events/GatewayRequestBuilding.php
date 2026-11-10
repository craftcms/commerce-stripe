<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Events;

use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\CommerceStripe\Gateway;

/**
 * Dispatched before a payment intent or Checkout session is created for a transaction. Listeners may modify `$request`,
 * which is sent to Stripe as the parameters of the create request.
 *
 * Changes to the transaction are not saved. Changes to the `amount` and `currency` of a payment intent are ignored.
 */
class GatewayRequestBuilding
{
    /**
     * @param string $type `payment_intent` or `checkout.session`
     * @param array<string, mixed> $request
     */
    public function __construct(
        public readonly Gateway $gateway,
        public readonly string $type,
        public readonly Transaction $transaction,
        public array $request,
    ) {
    }
}
