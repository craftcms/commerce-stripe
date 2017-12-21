<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\commerce\stripe\migrations;

use Craft;
use craft\commerce\stripe\gateways\Gateway;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\MigrationHelper;

/**
 * Installation Migration
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  1.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Convert any built-in Stripe gateways to ours
        $this->_convertGateways();

        $this->createTable('{{%stripe_customers}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'gatewayId' => $this->integer()->notNull(),
            'customerId' => $this->string()->notNull(),
            'response' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey($this->db->getForeignKeyName('{{%stripe_customers}}', 'gatewayId'), '{{%stripe_customers}}', 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE', null);
        $this->addForeignKey($this->db->getForeignKeyName('{{%stripe_customers}}', 'userId'), '{{%stripe_customers}}', 'userId', '{{%users}}', 'id', 'CASCADE', null);

        $this->createIndex($this->db->getIndexName('{{%stripe_customers}}', 'gatewayId', false), '{{%stripe_customers}}', 'gatewayId', false);
        $this->createIndex($this->db->getIndexName('{{%stripe_customers}}', 'userId', false), '{{%stripe_customers}}', 'userId', false);
        $this->createIndex($this->db->getIndexName('{{%stripe_customers}}', 'customerId', true), '{{%stripe_customers}}', 'customerId', true);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {

        MigrationHelper::dropAllForeignKeysOnTable('{{%stripe_customers}}', $this);
        $this->dropTable('{{%stripe_customers}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Converts any old school Stripe gateways to this one
     *
     * @return void
     */
    private function _convertGateways()
    {
        $gateways = (new Query())
            ->select(['id'])
            ->where(['type' => 'craft\\commerce\\gateways\\Stripe'])
            ->from(['{{%commerce_gateways}}'])
            ->all();

        $dbConnection = Craft::$app->getDb();

        foreach ($gateways as $gateway) {

            $values = [
                'type' => Gateway::class,
            ];

            $dbConnection->createCommand()
                ->update('{{%commerce_gateways}}', $values, ['id' => $gateway['id']])
                ->execute();
        }
    }
}
