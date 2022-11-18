<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use yii\base\Event;

/**
 * Class PaymentIntentConfirmationEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.4.4
 */
class PaymentIntentConfirmationEvent extends Event
{
    /**
     * @var array The parameters passed to the PaymentIntent.
     */
    public array $parameters;
}
