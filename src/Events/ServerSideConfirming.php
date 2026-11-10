<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Events;

use CraftCms\Cms\Shared\Concerns\ValidatableEvent;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\CommerceStripe\Gateway;
use Stripe\ConfirmationToken;

/**
 * Dispatched before a payment intent is created and confirmed with a ConfirmationToken, when the payment form uses
 * `serverSideConfirmation`.
 *
 * The confirmation token’s `payment_method_preview` describes the customer’s payment method. Set `$isValid` to
 * `false` to reject the payment before it is confirmed. `$errorMessage` is shown to the customer.
 */
class ServerSideConfirming
{
    use ValidatableEvent;

    public ?string $errorMessage = null;

    public function __construct(
        public readonly Gateway $gateway,
        public readonly Transaction $transaction,
        public readonly ConfirmationToken $confirmationToken,
    ) {
    }
}
