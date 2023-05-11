<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use craft\elements\User;
use yii\base\Event;

/**
 * Class CreateCustomerEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class CreateCustomerEvent extends Event
{
    /**
     * @var array The customer data passsed to Stripe
     * refer to stripe docs for available fields - https://stripe.com/docs/api/customers/object
     */
    public $customer;

    /**
     * @var User The currently logged in Craft user
     */
    public $user;
}
