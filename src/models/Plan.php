<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models;

use craft\commerce\base\Plan as BasePlan;
use craft\commerce\base\PlanInterface;
use craft\helpers\Json;

/**
 * Stripe Payment form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Plan extends BasePlan
{
    /**
     * @inheritdoc
     */
    public function canSwitchFrom(PlanInterface $currentPlant): bool
    {
        /** @var BasePlan $currentPlant */
        return $currentPlant->gatewayId === $this->gatewayId;
    }

    /**
     * Returns true if this plan is on the same payment cycle as another plan.
     *
     * @param Plan $plan
     * @return bool
     */
    public function isOnSamePaymentCycleAs(Plan $plan): bool
    {
        $thisPlanData = Json::decode($this->planData);
        $otherPlanData = Json::decode($plan->planData);

        $thisInfo = $thisPlanData['plan'] ?? $thisPlanData['price'] ?? [];
        $otherInfo = $otherPlanData['plan'] ?? $otherPlanData['price'] ?? [];

        $thisInterval = $thisInfo['interval'] ?? $thisInfo['recurring']['interval'] ?? null;
        $thisIntervalCount = $thisInfo['interval_count'] ?? $thisInfo['recurring']['interval_count'] ?? null;
        $otherInterval = $otherInfo['interval'] ?? $otherInfo['recurring']['interval'] ?? null;
        $otherIntervalCount = $otherInfo['interval_count'] ?? $otherInfo['recurring']['interval_count'] ?? null;

        return $thisInterval === $otherInterval && $thisIntervalCount === $otherIntervalCount;
    }
}
