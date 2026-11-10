<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Events;

use CraftCms\CommerceStripe\Gateway;

/**
 * Dispatched after a webhook’s signature has been verified, whether or not the gateway acted on it.
 *
 * `$webhookData` is the decoded [Stripe event](https://docs.stripe.com/api/events/object). Its `type` determines the
 * shape of `data`.
 */
class WebhookReceived
{
    /**
     * @param array<string, mixed> $webhookData
     */
    public function __construct(
        public readonly Gateway $gateway,
        public readonly array $webhookData,
    ) {
    }
}
