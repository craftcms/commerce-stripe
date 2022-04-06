<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models\forms;

use craft\commerce\models\subscriptions\CancelSubscriptionForm as BaseCancelSubscriptionForm;

/**
 * Stripe cancel subscription form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class CancelSubscription extends BaseCancelSubscriptionForm
{
    /**
     * @var bool whether the subscription should be canceled immediately
     */
    public bool $cancelImmediately = false;
}
