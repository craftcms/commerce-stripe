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
        $doSync = $this->prompt('This will sync down all payment sources in your stripe account, and create inactive local users if a local user is not found for that customer email ... do you wish to continue?', [
            'required' => true,
            'default' => 'no',
            'validator' => function($input) {
                if (!in_array($input, ['yes', 'no'])) {
                    $this->stderr('You must answer either "yes" or "no".' . PHP_EOL, Console::FG_RED);
                    return false;
                }

                return true;
            },
        ]);

        // get all gateways which inherit from Gateway
        $allGateways = collect(CommercePlugin::getInstance()->getGateways()->getAllGateways())->where(function($gateway) {
            return $gateway instanceof Gateway;
        })->pluck('handle')->all();

        // ask for which gateway handle to use
        $gatewayHandle = $this->prompt('Which gateway (handle) would you like to sync payment sources for?', [
            'required' => true,
            'default' => '',
            'validator' => function($input) use ($allGateways) {
                if (!in_array($input, $allGateways)) {
                    $this->stderr('You must answer either "stripe" or "stripe3".' . PHP_EOL, Console::FG_RED);
                    return false;
                }

                return true;
            },
        ]);

        /** @var Gateway $gateway */
        $gateway = CommercePlugin::getInstance()->getGateways()->getGatewayByHandle($gatewayHandle);

        if ($doSync == 'yes') {
            try {
                $this->stdout('Syncing...' . PHP_EOL . PHP_EOL, Console::FG_GREEN);
                $count = Plugin::getInstance()->getPaymentMethods()->syncAllPaymentMethods($gateway);
                $this->stdout('Synced ' . $count . ' payment methods.' . PHP_EOL . PHP_EOL, Console::FG_GREEN);
            } catch (Exception $e) {
                $this->stdout($e->getmessage() . PHP_EOL, Console::FG_RED);
            }
        } else {
            $this->stdout('Skipping data sync.' . PHP_EOL . PHP_EOL, Console::FG_GREEN);
        }
        $this->stdout('Done.' . PHP_EOL . PHP_EOL, Console::FG_GREEN);
        return ExitCode::OK;
    }
}
