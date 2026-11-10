<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Exceptions;

use CraftCms\Commerce\Payment\Exceptions\PaymentSourceException as BasePaymentSourceException;

/**
 * Thrown when a payment source can’t be created from a Stripe payment method.
 */
class PaymentSourceException extends BasePaymentSourceException
{
}
