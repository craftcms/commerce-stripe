<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use yii\base\Event;

/**
 * Class ReceiveWebhookEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class ReceiveWebhookEvent extends Event
{
    /**
     * @var array The webhook data
     */
    public $webhookData;
}
