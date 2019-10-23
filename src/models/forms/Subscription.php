<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models\forms;

use craft\commerce\models\subscriptions\SubscriptionForm;

/**
 * Subscription form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.2
 */
class Subscription extends SubscriptionForm
{
    /**
     * Timestamp for when the trial must end
     *
     * @var int
     */
    public $trialEnd;
}
