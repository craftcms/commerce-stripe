<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models\forms;

use craft\commerce\models\subscriptions\SubscriptionForm as BaseSubscriptionForm;

/**
 * Class SubscriptionForm
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class SubscriptionForm extends BaseSubscriptionForm
{
    /**
     * Metadata for the subscription.
     *
     * @var array
     */
    public $metadata = [];

    /**
     * Coupon to apply to the subscription.
     *
     * @var string
     */
    public $coupon;

    /**
     * Prorate the subscription.
     *
     * @var bool
     */
    public $prorate = true;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return array_merge(
            parent::rules(),
            [
                [['prorate'], 'boolean']
            ]
        );
    }
}
