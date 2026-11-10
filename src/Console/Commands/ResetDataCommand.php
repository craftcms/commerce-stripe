<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\CommerceStripe\Database\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Override;
use Throwable;

/**
 * Deletes the plugin’s data: Stripe customers, payment intents and invoices.
 */
class ResetDataCommand extends Command
{
    use CraftCommand;

    #[Override]
    protected $signature = 'commerce-stripe:reset-data {--force : Reset the data without asking for confirmation}';

    #[Override]
    protected $description = 'Deletes all Stripe customer, payment intent and invoice records.';

    #[Override]
    protected $aliases = ['commerce-stripe/reset-data'];

    public function handle(): int
    {
        $confirmed = $this->option('force') || $this->confirm('Resetting Stripe plugin data will permanently delete all customers, payment intents, and invoice records. Do you wish to continue?');

        if (!$confirmed) {
            $this->components->info('Skipping data reset.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function() {
                foreach ([Table::PAYMENTINTENTS, Table::INVOICES, Table::CUSTOMERS] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Finished resetting Stripe plugin data.');

        return self::SUCCESS;
    }
}
