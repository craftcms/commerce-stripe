<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Commerce\Payment\Events\PrimaryPaymentSourceUpdated;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;
use CraftCms\CommerceStripe\Console\Commands\ResetDataCommand;
use CraftCms\CommerceStripe\Console\Commands\SyncPaymentSourcesCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * Stripe for Craft Commerce.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '4.0.0';

    public string $minVersionRequired = '5.2.3';

    protected array $commands = [
        ResetDataCommand::class,
        SyncPaymentSourcesCommand::class,
    ];

    public function boot(GatewayTypes $gatewayTypes): void
    {
        $gatewayTypes->register(Gateway::class);

        Event::listen(PrimaryPaymentSourceUpdated::class, [PaymentMethods::class, 'handlePrimaryPaymentSourceUpdated']);
    }

    /**
     * Returns all Stripe gateways.
     *
     * @return Collection<int, Gateway>
     */
    public function getStripeGateways(): Collection
    {
        /** @var Collection<int, Gateway> $gateways */
        $gateways = app(Gateways::class)->getAllGateways()
            ->filter(fn($gateway) => $gateway instanceof Gateway)
            ->values();

        return $gateways;
    }
}
