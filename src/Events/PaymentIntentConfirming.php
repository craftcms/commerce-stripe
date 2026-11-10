<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Events;

use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\CommerceStripe\Gateway;

/**
 * Dispatched before a payment intent is confirmed on the server. Listeners may modify `$parameters`, such as the
 * `return_url`. Changes to `amount` and `currency` are ignored.
 */
class PaymentIntentConfirming
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly Gateway $gateway,
        public readonly Transaction $transaction,
        public array $parameters,
    ) {
    }
}
