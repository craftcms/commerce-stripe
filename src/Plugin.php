<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe;

use craft\commerce\events\UpdatePrimaryPaymentSourceEvent;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\services\Customers;
use craft\commerce\services\Gateways;
use craft\commerce\stripe\base\Gateway;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\commerce\stripe\models\Settings;
use craft\commerce\stripe\plugin\Services;
use craft\events\RegisterComponentTypesEvent;
use yii\base\Event;
use yii\base\InvalidConfigException;

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
            }
        );

        Event::on(
            Customers::class,
            Customers::EVENT_UPDATE_PRIMARY_PAYMENT_SOURCE,
            [$this, 'handlePrimaryPaymentSourceUpdated']
        );
    }

    /**
     * Whenever a payment source is set as primary in Commerce, lets make it the primary in the gateway too.
     *
     * @param UpdatePrimaryPaymentSourceEvent $event
     * @return void
     * @throws InvalidConfigException
     */
    public function handlePrimaryPaymentSourceUpdated(UpdatePrimaryPaymentSourceEvent $event): void
    {
        $paymentSourceService = CommercePlugin::getInstance()->getPaymentSources();
        $newPrimaryPaymentSource = $paymentSourceService->getPaymentSourceById($event->newPrimaryPaymentSourceId);
        $stripeCustomerReference = $this->getCustomers()->getCustomer($newPrimaryPaymentSource->getGateway()->id, $event->customer)->reference;
        $gateway = $newPrimaryPaymentSource->getGateway();

        if ($gateway instanceof Gateway) {
            $gateway->setPaymentSourceAsDefault($stripeCustomerReference, $newPrimaryPaymentSource->token);
        }
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }
}
