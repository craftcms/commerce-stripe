<?php

namespace craft\commerce\stripe\migrations;

use craft\db\Migration;
use craft\helpers\MigrationHelper;

/**
 * m230815_160426_remove_orderId_payment_intents migration.
 */
class m230815_160426_remove_orderId_payment_intents extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        MigrationHelper::dropAllForeignKeysOnTable('{{%stripe_paymentintents}}', $this);
        MigrationHelper::dropAllIndexesOnTable('{{%stripe_paymentintents}}', $this);

        $this->dropColumn('{{%stripe_paymentintents}}', 'orderId');

        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE');
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'customerId', '{{%stripe_customers}}', 'id', 'CASCADE');

        $this->createIndex(null, '{{%stripe_paymentintents}}', 'reference', true);
        $this->createIndex(null, '{{%stripe_paymentintents}}', ['gatewayId', 'customerId', 'transactionHash'], true);


        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230815_160426_remove_orderId_payment_intents cannot be reverted.\n";
        return false;
    }
}
