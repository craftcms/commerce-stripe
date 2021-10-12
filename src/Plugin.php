<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe;

use craft\commerce\services\Gateways;
use craft\commerce\stripe\gateways\Gateway;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\commerce\stripe\models\Settings;
use craft\commerce\stripe\plugin\Services;
use craft\events\RegisterComponentTypesEvent;
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
    public string $schemaVersion = '2.4.0';

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
                $event->types[] = Gateway::class;
                $event->types[] = PaymentIntents::class;
            }
        );
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }
}
