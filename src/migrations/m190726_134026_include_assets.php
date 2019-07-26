<?php

namespace craft\commerce\stripe\migrations;

use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\gateways\Gateway;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\db\Migration;

/**
 * m190726_134026_include_assets migration.
 */
class m190726_134026_include_assets extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Place migration code here...
        $gateways = Commerce::getInstance()->getGateways()->getAllGateways();

        foreach ($gateways as $gateway) {
            if ($gateway instanceof Gateway || $gateway instanceof PaymentIntents) {
                $gateway->includeAssets = true;
                Commerce::getInstance()->getGateways()->saveGateway($gateway);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m190726_134026_include_assets cannot be reverted.\n";
        return false;
    }
}
