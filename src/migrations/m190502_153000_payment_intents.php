<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\stripe\migrations;

use craft\db\Migration;

/**
 * m190502_153000_payment_intents migration.
 */
class m190502_153000_payment_intents extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $this->createTable('{{%stripe_paymentintents}}', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(),
            'gatewayId' => $this->integer()->notNull(),
            'customerId' => $this->integer()->notNull(),
            'orderId' => $this->integer()->notNull(),
            'intentData' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE', null);
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'customerId', '{{%stripe_customers}}', 'id', 'CASCADE', null);
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'orderId', '{{%commerce_orders}}', 'id', 'CASCADE', null);

        $this->createIndex(null, '{{%stripe_paymentintents}}', 'reference', true);
        $this->createIndex(null, '{{%stripe_paymentintents}}', ['orderId', 'gatewayId', 'customerId'], true);
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m190502_153000_payment_intents cannot be reverted.\n";
        return false;
    }
}
