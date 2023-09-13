<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use craft\commerce\base\Plan;
use craft\elements\User;
use yii\base\Event;

/**
 * Class SubscriptionRequestEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class SubscriptionRequestEvent extends Event
{
    /**
     * @var Plan The subscription plan
     */
    public $plan;

    /**
     * @var array The subscription parameters
     */
    public array $parameters;

    /**
     * @var User The user the subscription will belong to
     */
    public User $user;
}
