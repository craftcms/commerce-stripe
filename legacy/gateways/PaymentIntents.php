<?php

namespace craft\commerce\stripe\gateways;

// Gateways saved with Stripe for Craft Commerce 2.x to 6.x store this class name as their type.
/** @deprecated in 7.0.0. Use {@see \CraftCms\CommerceStripe\Gateway} instead. */
class_alias(\CraftCms\CommerceStripe\Gateway::class, 'craft\commerce\stripe\gateways\PaymentIntents');

/** @phpstan-ignore-next-line */
if (false) {
    class PaymentIntents extends \CraftCms\CommerceStripe\Gateway
    {
    }
}
