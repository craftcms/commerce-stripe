<?php

namespace craft\commerce\stripe\events;

use craft\commerce\models\Transaction;
use yii\base\Event;

/**
 * Class BuildGatewayRequestEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class BuildGatewayRequestEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var array The metadata of the gateway request
     */
    public $metadata;

    /**
     * @var Transaction The transaction being used as the base for request
     */
    public $transaction;
}
