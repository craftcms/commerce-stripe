<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use craft\commerce\models\Transaction;
use yii\base\Event;

/**
 * Class ReceiveWebhookEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Receive3dsPaymentEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var Transaction The successful transaction
     */
    public $transaction;
}
