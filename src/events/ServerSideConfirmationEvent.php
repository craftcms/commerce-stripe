<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use craft\commerce\models\Transaction;
use craft\events\CancelableEvent;
use Stripe\ConfirmationToken;

/**
 * Class ServerSideConfirmationEvent
 *
 * Triggered before a payment intent is created and confirmed with a ConfirmationToken. Set `$isValid` to `false`
 * to reject the payment, and `$errorMessage` to the message shown to the customer.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 6.0.0
 */
class ServerSideConfirmationEvent extends CancelableEvent
{
    /**
     * @var Transaction The transaction being paid.
     */
    public Transaction $transaction;

    /**
     * @var ConfirmationToken The ConfirmationToken created by Stripe.js. Its `payment_method_preview` contains
     * details about the customer’s payment method, like the type, card brand, funding and country.
     */
    public ConfirmationToken $confirmationToken;

    /**
     * @var string|null The error message shown to the customer when the payment is rejected.
     */
    public ?string $errorMessage = null;
}
