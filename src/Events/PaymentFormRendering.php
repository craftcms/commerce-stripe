<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Events;

use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\CommerceStripe\Gateway;

/**
 * Dispatched before the payment form is rendered. `$params` have already been merged with the defaults, and `$order`
 * is the order passed with the `order` param, if any. Listeners may modify `$params`.
 */
class PaymentFormRendering
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly Gateway $gateway,
        public array $params,
        public readonly ?Order $order = null,
    ) {
    }
}
