<?php

namespace craft\commerce\stripe\models;

use craft\commerce\base\Plan as BasePlan;

/**
 * Stripe Payment form model.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class Subscription extends BasePlan
{
    /**
     * @inheritdoc
     */
    public function getFriendlyName(): string
    {
        return $this->getPlanData()['name'];
    }


}