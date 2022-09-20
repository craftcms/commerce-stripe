<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models\forms;

use craft\commerce\models\subscriptions\SwitchPlansForm;

/**
 * Switch Plans form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class SwitchPlans extends SwitchPlansForm
{
    /**
     * Whether plan change should be prorated
     *
     * @var bool
     */
    public ?bool $prorate = false;

    /**
     * @var bool Whether the plan change should be billed immediately.
     */
    public bool $billImmediately = false;

    /**
     * @var string|int The billing cycle anchor. Can be set to `now` or `unchanged` (default).
     */
    public string|int $billingCycleAnchor;

    /**
     * @var int|null Timestamp on which to base the proration calculation
     */
    public ?int $prorationDate = null;

    /**
     * @var int|null Quantity of subscription
     */
    public ?int $quantity = null;
}
