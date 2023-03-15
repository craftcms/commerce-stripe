<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe;

use craft\commerce\events\PaymentSourceEvent;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\services\Gateways;
use craft\commerce\services\PaymentSources;
use craft\commerce\stripe\base\Gateway;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\commerce\stripe\gateways\PaymentIntentsElementsCheckout;
use craft\commerce\stripe\models\Settings;
use craft\commerce\stripe\plugin\Services;
use craft\commerce\stripe\utilities\Sync;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use Illuminate\Support\Collection;
use yii\base\Event;

/**
 * Plugin represents the Stripe integration plugin.
 *
 * @method Settings getSettings()
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 *
 * @property-read Settings $settings
 */
class Plugin extends \craft\base\Plugin
{
    /**
     * @inheritDoc
     */
    public string $schemaVersion = '3.0.0';

    use Services;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_setPluginComponents();

        Event::on(
            Gateways::class,
            Gateways::EVENT_REGISTER_GATEWAY_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = PaymentIntents::class;
                $event->types[] = PaymentIntentsElementsCheckout::class;
            }
        );

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITY_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Sync::class;
            }
        );

        Event::on(
            PaymentSources::class,
            PaymentSources::EVENT_DELETE_PAYMENT_SOURCE,
            function(PaymentSourceEvent $event) {
                Plugin::getInstance()->getPaymentMethods()->deletePaymentMethod($event->paymentSource);
            }
        );
    }

    /**
     * @return Collection<Gateway>
     * @throws \yii\base\InvalidConfigException
     */
    public function getStripeGateways()
    {
        return collect(CommercePlugin::getInstance()->getGateways()->getAllGateways())
            ->filter(function($gateway) {
                return $gateway instanceof Gateway;
            });
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }
}
