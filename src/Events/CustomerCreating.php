<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Events;

use CraftCms\Cms\User\Elements\User;
use CraftCms\CommerceStripe\Gateway;

/**
 * Dispatched before a customer is created in Stripe for a user. Listeners may modify `$customer`, which is sent to
 * Stripe as the parameters of the [create customer](https://docs.stripe.com/api/customers/create) request.
 */
class CustomerCreating
{
    /**
     * @param array<string, mixed> $customer
     */
    public function __construct(
        public readonly Gateway $gateway,
        public readonly User $user,
        public array $customer,
    ) {
    }
}
