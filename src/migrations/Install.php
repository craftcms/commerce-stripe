<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\migrations;

use Craft;
use craft\commerce\stripe\gateways\Gateway;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\MigrationHelper;

/**
 * Installation Migration
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Convert any built-in Stripe gateways to ours
        $this->_convertGateways();

        $this->createTable('{{%stripe_customers}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'gatewayId' => $this->integer()->notNull(),
            'reference' => $this->string()->notNull(),
            'response' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%stripe_invoices}}', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(),
            'subscriptionId' => $this->integer()->notNull(),
            'invoiceData' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%stripe_paymentintents}}', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(),
            'gatewayId' => $this->integer()->notNull(),
            'customerId' => $this->integer()->notNull(),
            'orderId' => $this->integer()->notNull(),
            'transactionHash' => $this->string()->notNull(),
            'intentData' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, '{{%stripe_customers}}', 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE');
        $this->addForeignKey(null, '{{%stripe_customers}}', 'userId', '{{%users}}', 'id', 'CASCADE');
        $this->addForeignKey(null, '{{%stripe_invoices}}', 'subscriptionId', '{{%commerce_subscriptions}}', 'id', 'CASCADE');
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE');
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'customerId', '{{%stripe_customers}}', 'id', 'CASCADE');
        $this->addForeignKey(null, '{{%stripe_paymentintents}}', 'orderId', '{{%commerce_orders}}', 'id', 'CASCADE');

        $this->createIndex(null, '{{%stripe_customers}}', 'gatewayId', false);
        $this->createIndex(null, '{{%stripe_customers}}', 'userId', false);
        $this->createIndex(null, '{{%stripe_customers}}', 'reference', true);
        $this->createIndex(null, '{{%stripe_invoices}}', 'subscriptionId', false);
        $this->createIndex(null, '{{%stripe_invoices}}', 'reference', true);
        $this->createIndex(null, '{{%stripe_paymentintents}}', 'reference', true);
        $this->createIndex(null, '{{%stripe_paymentintents}}', ['orderId', 'gatewayId', 'customerId', 'transactionHash'], true);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {

        MigrationHelper::dropAllForeignKeysOnTable('{{%stripe_invoices}}', $this);
        MigrationHelper::dropAllForeignKeysOnTable('{{%stripe_customers}}', $this);
        MigrationHelper::dropAllForeignKeysOnTable('{{%stripe_paymentintents}}', $this);
        $this->dropTable('{{%stripe_customers}}');
        $this->dropTable('{{%stripe_invoices}}');
        $this->dropTable('{{%stripe_paymentintents}}');

        return true;
    }

    /**
     * Converts any old school Stripe gateways to this one
     */
    private function _convertGateways()
    {
        $gateways = (new Query())
            ->select(['id', 'settings'])
            ->where(['type' => 'craft\\commerce\\gateways\\Stripe'])
            ->from(['{{%commerce_gateways}}'])
            ->all();

        $dbConnection = Craft::$app->getDb();

        foreach ($gateways as $gateway) {

            $settings = Json::decodeIfJson($gateway['settings']);

            if ($settings && isset($settings['includeReceiptEmailInRequests'])) {
                $settings['sendReceiptEmail'] = $settings['includeReceiptEmailInRequests'];
                unset($settings['includeReceiptEmailInRequests']);
            } else {
                $settings = [];
            }

            $settings = Json::encode($settings);

            $values = [
                'type' => Gateway::class,
                'settings' => $settings,
            ];

            $dbConnection->createCommand()
                ->update('{{%commerce_gateways}}', $values, ['id' => $gateway['id']])
                ->execute();
        }
    }
}
