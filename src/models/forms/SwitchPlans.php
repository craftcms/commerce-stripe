<?php

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
     * @var int
     */
    public $prorate = false;

    /**
     * @var bool whether the plan change should be billed immediately.
     */
    public $billImmediately = false;
}
