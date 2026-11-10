<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Tests;

use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\CommerceStripe\Tests\Support\FakeStripeHttpClient;
use Override;
use Stripe\ApiRequestor;

use function Orchestra\Testbench\package_path;

/**
 * Installs Craft, Craft Commerce and this plugin, and replaces the Stripe SDK’s HTTP client with a fake.
 */
abstract class TestCase extends PluginTestCase
{
    public FakeStripeHttpClient $stripe;

    #[Override]
    protected function setUp(): void
    {
        // Commerce needs more than one user in some tests, and the Solo edition only allows one.
        putenv('CRAFT_EDITION=pro');
        unset($_SERVER['CRAFT_SITE']);

        parent::setUp();

        // Orders always have a customer (a guest user, for guest checkouts), so most payments create a Stripe customer.
        $this->stripe = new FakeStripeHttpClient()
            ->default('POST', '/v1/customers', ['id' => 'cus_test', 'object' => 'customer']);
        ApiRequestor::setHttpClient($this->stripe);
    }

    #[Override]
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /**
     * Installs Commerce before this plugin. {@see PluginTestCase} only knows about the root package.
     */
    #[Override]
    public function setupInstallsPlugin(): void
    {
        $composerPlugins = require package_path('vendor/craftcms/plugins.php');
        $handle = $composerPlugins[array_key_first(array_filter($composerPlugins, fn(array $plugin) => $plugin['basePath'] === package_path('src')))]['handle'];

        $this->app->when(Plugins::class)->needs('$composerPlugins')->give(fn() => $composerPlugins);

        foreach (['commerce', $handle] as $pluginHandle) {
            // Plugins::installPlugin() caches the loaded plugins, so each install needs a fresh instance.
            $this->app->forgetInstance(Plugins::class);
            $this->app->get(Plugins::class)->installPlugin($pluginHandle);
        }

        $this->app->forgetInstance(Plugins::class);
        $this->app->get(Plugins::class)->loadPlugins();

        $routes = $this->app->get('router')->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }
}
