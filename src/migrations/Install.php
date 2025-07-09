<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\migrations;

use craft\commerce\stripe\db\Table;
use craft\db\Migration;
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
        $this->archiveTableIfExists(Table::CUSTOMERS);
        $this->createTable(Table::CUSTOMERS, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'gatewayId' => $this->integer()->notNull(),
            'reference' => $this->string()->notNull(),
            'response' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists(Table::INVOICES);
        $this->createTable(Table::INVOICES, [
            'id' => $this->primaryKey(),
            'reference' => $this->string(),
            'subscriptionId' => $this->integer()->notNull(),
            'invoiceData' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists(Table::PAYMENTINTENTS);
        $this->createTable(Table::PAYMENTINTENTS, [
            'id' => $this->primaryKey(),
            'reference' => $this->string(),
            'gatewayId' => $this->integer()->notNull(),
            'customerId' => $this->integer()->notNull(),
            'transactionHash' => $this->string()->notNull(),
            'intentData' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, Table::CUSTOMERS, 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE');
        $this->addForeignKey(null, Table::CUSTOMERS, 'userId', '{{%users}}', 'id', 'CASCADE');
        $this->addForeignKey(null, Table::INVOICES, 'subscriptionId', '{{%commerce_subscriptions}}', 'id', 'CASCADE');
        $this->addForeignKey(null, Table::PAYMENTINTENTS, 'gatewayId', '{{%commerce_gateways}}', 'id', 'CASCADE');
        $this->addForeignKey(null, Table::PAYMENTINTENTS, 'customerId', Table::CUSTOMERS, 'id', 'CASCADE');

        $this->createIndex(null, Table::CUSTOMERS, 'gatewayId', false);
        $this->createIndex(null, Table::CUSTOMERS, 'userId', false);
        $this->createIndex(null, Table::CUSTOMERS, 'reference', true);
        $this->createIndex(null, Table::INVOICES, 'subscriptionId', false);
        $this->createIndex(null, Table::INVOICES, 'reference', true);
        $this->createIndex(null, Table::PAYMENTINTENTS, 'reference', true);
        $this->createIndex(null, Table::PAYMENTINTENTS, ['gatewayId', 'customerId', 'transactionHash'], true);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        MigrationHelper::dropAllForeignKeysOnTable(Table::INVOICES, $this);
        MigrationHelper::dropAllForeignKeysOnTable(Table::CUSTOMERS, $this);
        MigrationHelper::dropAllForeignKeysOnTable(Table::PAYMENTINTENTS, $this);
        $this->dropTable(Table::CUSTOMERS);
        $this->dropTable(Table::INVOICES);
        $this->dropTable(Table::PAYMENTINTENTS);

        return true;
    }
}
