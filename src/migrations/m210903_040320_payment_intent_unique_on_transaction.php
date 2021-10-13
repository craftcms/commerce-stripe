<?php

namespace craft\commerce\stripe\migrations;

use Craft;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\MigrationHelper;
use yii\db\Expression;

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

        $gatewayTypes = (new Query())
            ->select(['id', 'type'])
            ->from(['{{%commerce_gateways}}'])
            ->pairs();

        $gatewayIds = [];
        foreach ($gatewayTypes as $id => $gatewayType) {
            if (is_a(PaymentIntents::class, $gatewayType, true)) {
                $gatewayIds[] = $id;
            }
        }

        $transactions = (new Query())
            ->select(['reference', 'hash'])
            ->from(['{{%commerce_transactions}}'])
            ->where(['LIKE', 'reference', 'pi_%', false]) // they are using a payment intent identifier
            ->andWhere(['gatewayId' => $gatewayIds])
            ->all();

        foreach ($transactions as $transaction) {
            $this->update('{{%stripe_paymentintents}}', ['transactionHash' => $transaction['hash']], ['reference' => $transaction['reference']]);
        }

        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE', null);
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'customerId', '{{%stripe_customers}}', 'id', 'CASCADE', null);
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'orderId', '{{%commerce_orders}}', 'id', 'CASCADE', null);

        $this->createIndex(null, '{{%stripe_paymentintents}}', 'reference', true);
        $this->createIndex(null, '{{%stripe_paymentintents}}', ['orderId', 'gatewayId', 'customerId', 'transactionHash'], true);
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m210903_040320_payment_intent_unique_on_transaction cannot be reverted.\n";
        return false;
    }
}
