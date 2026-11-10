<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Events;

use CraftCms\CommerceStripe\Gateway;

/**
 * Dispatched before a setup intent is created. Listeners may modify `$request`, which is sent to Stripe as the
 * parameters of the [create setup intent](https://docs.stripe.com/api/setup_intents/create) request.
 */
class SetupIntentRequestBuilding
{
    /**
     * @param array<string, mixed> $request
     */
    public function __construct(
        public readonly Gateway $gateway,
        public array $request,
    ) {
    }
}
