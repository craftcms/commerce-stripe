<?php

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
    // Properties
    // =========================================================================

    /**
     * @var array The webhook data
     */
    public $webhookData;
}
