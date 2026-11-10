<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use craft\commerce\elements\Order;
use yii\base\Event;

/**
 * Class PaymentFormEvent
 *
 * Triggered before the Stripe payment form is rendered, so the params passed to `getPaymentFormHtml()`
 * can be modified.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 6.0.0
 */
class PaymentFormEvent extends Event
{
    /**
     * @var array The payment form params, merged with the defaults.
     */
    public array $params = [];

    /**
     * @var Order|null The order passed to the payment form with the `order` param, if any.
     */
    public ?Order $order = null;
}
