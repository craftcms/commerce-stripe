<?php

namespace craft\commerce\stripe\migrations;

use craft\db\Migration;
use craft\helpers\MigrationHelper;

/**
 * m210903_040320_payment_intent_unique_on_transaction migration.
 */
class m210903_040320_payment_intent_unique_on_transaction extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        if (!$this->db->columnExists('{{%stripe_paymentintents}}', 'transactionHash')) {
            $this->addColumn('{{%stripe_paymentintents}}', 'transactionHash', $this->string()->after('orderId'));
        }

        MigrationHelper::dropAllForeignKeysOnTable('{{%stripe_paymentintents}}', $this);
        MigrationHelper::dropAllIndexesOnTable('{{%stripe_paymentintents}}', $this);

        $transactionsTable = '{{%commerce_transactions}}';
        $stripePaymentIntentsTable = '{{%stripe_paymentintents}}';

        if ($this->db->getIsMysql()) {
            $sql = <<<SQL
UPDATE $stripePaymentIntentsTable [[pi]]
INNER JOIN $transactionsTable [[t]] ON [[t.reference]] = [[pi.reference]]
SET [[pi.transactionHash]] = [[t.hash]]
WHERE [[pi.transactionHash]] IS NULL
SQL;
        } else {
            $sql = <<<SQL
UPDATE $stripePaymentIntentsTable [[pi]]
SET [[transactionHash]] = [[t.hash]]
FROM $transactionsTable [[t]]
WHERE
[[pi.transactionHash]] IS NULL AND
[[t.reference]] = [[pi.reference]]
SQL;
        }

        $this->execute($sql);

        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE', null);
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'customerId', '{{%stripe_customers}}', 'id', 'CASCADE', null);
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'orderId', '{{%commerce_orders}}', 'id', 'CASCADE', null);

        $this->createIndex(null, '{{%stripe_paymentintents}}', 'reference', true);
        $this->createIndex(null, '{{%stripe_paymentintents}}', ['orderId', 'gatewayId', 'customerId', 'transactionHash'], true);
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m210903_040320_payment_intent_unique_on_transaction cannot be reverted.\n";
        return false;
    }
}
