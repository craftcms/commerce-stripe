<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use craft\commerce\base\Plan;
use yii\base\Event;

/**
 * Class SubscriptionRequestEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class SubscriptionRequestEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var Plan The subscription plan
     */
    public $plan;

    /**
     * @var array The subscription parameters
     */
    public $parameters;
}
