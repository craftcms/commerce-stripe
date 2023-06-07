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
 * Class BuildGatewayRequestEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class BuildGatewayRequestEvent extends Event
{
    /**
     * @var Transaction The transaction being used as the base for request
     */
    public Transaction $transaction;

    /**
     * @var array The request being used
     */
    public array $request;
}
