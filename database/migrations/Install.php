<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table as CraftTable;
use CraftCms\Commerce\Database\Table as CommerceTable;
use CraftCms\CommerceStripe\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the plugin’s tables, and drops them when the plugin is uninstalled.
 */
class Install extends Migration
{
    public function up(): void
    {
        $this->createTables();
        $this->addForeignKeys();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([Table::PAYMENTINTENTS, Table::INVOICES, Table::CUSTOMERS] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    private function createTables(): void
    {
        $this->archiveTableIfExists(Table::CUSTOMERS);
        Schema::create(Table::CUSTOMERS, function(Blueprint $table) {
            $table->integer('id', true);
            $table->integer('userId');
            $table->integer('gatewayId');
            $table->string('reference');
            $table->text('response')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');

            $table->index('gatewayId');
            $table->index('userId');
            $table->unique('reference');
        });

        // Invoices belonged to Commerce subscriptions, which Commerce 6 removed. The table is still created so the
        // schema matches sites that upgraded from earlier versions, and so their data is kept.
        $this->archiveTableIfExists(Table::INVOICES);
        Schema::create(Table::INVOICES, function(Blueprint $table) {
            $table->integer('id', true);
            $table->string('reference')->nullable();
            $table->integer('subscriptionId');
            $table->text('invoiceData')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');

            $table->index('subscriptionId');
            $table->unique('reference');
        });

        $this->archiveTableIfExists(Table::PAYMENTINTENTS);
        Schema::create(Table::PAYMENTINTENTS, function(Blueprint $table) {
            $table->integer('id', true);
            $table->string('reference')->nullable();
            $table->integer('gatewayId');
            $table->integer('customerId');
            $table->string('transactionHash');
            $table->text('intentData')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');

            $table->unique('reference');
            $table->unique(['gatewayId', 'customerId', 'transactionHash']);
        });
    }

    private function addForeignKeys(): void
    {
        Schema::table(Table::CUSTOMERS, function(Blueprint $table) {
            $table->foreign('gatewayId')->references('id')->on(CommerceTable::GATEWAYS)->cascadeOnDelete();
            $table->foreign('userId')->references('id')->on(CraftTable::USERS)->cascadeOnDelete();
        });

        Schema::table(Table::INVOICES, function(Blueprint $table) {
            $table->foreign('subscriptionId')->references('id')->on(CommerceTable::SUBSCRIPTIONS)->cascadeOnDelete();
        });

        Schema::table(Table::PAYMENTINTENTS, function(Blueprint $table) {
            $table->foreign('gatewayId')->references('id')->on(CommerceTable::GATEWAYS)->cascadeOnDelete();
            $table->foreign('customerId')->references('id')->on(Table::CUSTOMERS)->cascadeOnDelete();
        });
    }
}
