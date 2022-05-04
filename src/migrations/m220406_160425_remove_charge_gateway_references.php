<?php

namespace craft\commerce\stripe\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * m220406_160425_remove_charge_gateway_references migration.
 */
class m220406_160425_remove_charge_gateway_references extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $chargeGatewayIds = (new Query())
            ->select(['id'])
            ->where(['type' => 'craft\\commerce\\stripe\\gateways\\Gateway'])
            ->from(['{{%commerce_gateways}}'])
            ->column();

        if (empty($chargeGatewayIds)) {
            return true;
        }

        // Remove related gateway data
        $this->update('{{%commerce_orders}}', ['gatewayId' => null], ['gatewayId' => $chargeGatewayIds]);
        $this->update('{{%commerce_transactions}}', ['gatewayId' => null], ['gatewayId' => $chargeGatewayIds]);
        $this->delete('{{%commerce_paymentsources}}', ['gatewayId' => $chargeGatewayIds]);

        // Remove gateways
        $this->delete('{{%commerce_gateways}}', ['id' => $chargeGatewayIds]);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m220406_160425_remove_charge_gateway_references cannot be reverted.\n";
        return false;
    }
}
