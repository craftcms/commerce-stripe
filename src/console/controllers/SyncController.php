<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\stripe\console\controllers;

use craft\commerce\console\Controller;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\stripe\base\Gateway;
use craft\commerce\stripe\Plugin;
use craft\helpers\Console;
use Exception;
use yii\console\ExitCode;

/**
 * Allows you to sync Stripe data down to commerce
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 */
class SyncController extends Controller
{
    /**
     * Sync Payment Sources.
     */
    public function actionPaymentSources(): int
    {
        // Find all gateways that inherit from our base class:
        $allGateways = collect(CommercePlugin::getInstance()->getGateways()->getAllGateways())->where(function($gateway) {
            return $gateway instanceof Gateway;
        })->mapWithKeys(function($gateway) {
            // Convert to a format compatible with `Console::select()`:
            return [$gateway->handle => $gateway->name];
        })->all();

        // Did we find any?
        if (empty($allGateways)) {
            $this->stdout('No Stripe gateways exist.');

            return ExitCode::OK;
        }

        // Prompt for the gateway handle:
        $gatewayHandle = $this->select('Which gateway would you like to sync payment sources for?', $allGateways);

        /** @var Gateway $gateway */
        $gateway = CommercePlugin::getInstance()->getGateways()->getGatewayByHandle($gatewayHandle);

        $this->stdout('This will sync down all payment sources in your Stripe account, and create inactive local users if one is not found for that customer’s email.' . PHP_EOL);
        $this->stdout('If you are using testing keys, your development environment may end up with inconsistent customer information.' . PHP_EOL, Console::FG_YELLOW);

        if (!$this->confirm('Do you want to continue?')) {
            return ExitCode::OK;
        }

        try {
            $this->stdout('Syncing... ');
            $count = Plugin::getInstance()->getPaymentMethods()->syncAllPaymentMethods($gateway);
            $this->stdout('done! ' . PHP_EOL, Console::FG_GREEN);
            $this->stdout('Synchronized ');
            $this->stdout($count, Console::FG_BLUE);
            $this->stdout(' payment method(s).' . PHP_EOL);
        } catch (Exception $e) {
            $this->stdout(PHP_EOL . $e->getmessage() . PHP_EOL, Console::FG_RED);
        }

        return ExitCode::OK;
    }
}
