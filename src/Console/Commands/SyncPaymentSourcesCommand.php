<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\CommerceStripe\Gateway;
use CraftCms\CommerceStripe\PaymentMethods;
use CraftCms\CommerceStripe\Plugin;
use Illuminate\Console\Command;
use Override;
use Throwable;

/**
 * Syncs the payment methods of every customer in a gateway’s Stripe account to Commerce payment sources.
 */
class SyncPaymentSourcesCommand extends Command
{
    use CraftCommand;

    #[Override]
    protected $signature = 'commerce-stripe:sync:payment-sources';

    #[Override]
    protected $description = 'Syncs the payment methods of all Stripe customers to Commerce payment sources.';

    #[Override]
    protected $aliases = ['commerce-stripe/sync/payment-sources'];

    public function handle(Plugin $plugin, Gateways $gateways, PaymentMethods $paymentMethods): int
    {
        $options = $plugin->getStripeGateways()
            ->mapWithKeys(fn(Gateway $gateway) => [(string)$gateway->handle => (string)$gateway->name])
            ->all();

        if (empty($options)) {
            $this->components->info('No Stripe gateways exist.');

            return self::SUCCESS;
        }

        $handle = $this->choice('Which gateway would you like to sync payment sources for?', $options);

        /** @var Gateway $gateway */
        $gateway = $gateways->getGatewayByHandle((string)$handle);

        $this->line('This will sync down all payment sources in your Stripe account, for customers that already exist in Craft.');
        $this->components->warn('If you are using testing keys, your development environment may end up with inconsistent customer information.');

        if (!$this->confirm('Do you want to continue?')) {
            return self::SUCCESS;
        }

        try {
            $count = $paymentMethods->syncAllPaymentMethods($gateway);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Synchronized $count payment method(s).");

        return self::SUCCESS;
    }
}
