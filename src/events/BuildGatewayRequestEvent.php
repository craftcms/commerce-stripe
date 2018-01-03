<?php

namespace craft\commerce\stripe\events;

use craft\commerce\models\Transaction;
use craft\events\CancelableEvent;

/**
 * Class BuildGatewayRequestEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  1.0
 */
class BuildGatewayRequestEvent extends CancelableEvent
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
