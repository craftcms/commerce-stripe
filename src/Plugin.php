<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe;

use craft\commerce\events\PaymentSourceEvent;
use craft\commerce\events\UpdatePrimaryPaymentSourceEvent;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\services\Customers as CommerceCustomers;
use craft\commerce\services\Gateways;
use craft\commerce\services\PaymentSources;
use craft\commerce\stripe\base\Gateway;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\commerce\stripe\models\Settings;
use craft\commerce\stripe\plugin\Services;
use craft\commerce\stripe\services\Invoices;
use craft\commerce\stripe\services\PaymentIntents as PaymentIntentsService;
use craft\commerce\stripe\services\PaymentMethods;
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
     * @inheritDoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'customers' => Customers::class,
                'invoices' => Invoices::class,
                'paymentIntents' => PaymentIntentsService::class,
                'paymentMethods' => PaymentMethods::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        Event::on(
            Gateways::class,
            Gateways::EVENT_REGISTER_GATEWAY_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = PaymentIntents::class;
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
            });

        Event::on(
            CommerceCustomers::class,
            CommerceCustomers::EVENT_UPDATE_PRIMARY_PAYMENT_SOURCE,
            [$this, 'handlePrimaryPaymentSourceUpdated']
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
     * Whenever a payment source is set as primary in Commerce, lets make it the primary in the gateway too .
     *
     * @param UpdatePrimaryPaymentSourceEvent $event
     * @return void
     * @throws InvalidConfigException
     */
    public function handlePrimaryPaymentSourceUpdated(UpdatePrimaryPaymentSourceEvent $event): void
    {
        $paymentSourceService = CommercePlugin::getInstance()->getPaymentSources();
        $newPrimaryPaymentSource = $paymentSourceService->getPaymentSourceById($event->newPrimaryPaymentSourceId);
        /** @var Gateway $gateway * */
        $gateway = $newPrimaryPaymentSource->getGateway();
        if ($gateway instanceof Gateway) {
            $stripeCustomerReference = $this->getCustomers()->getCustomer($gateway->id, $event->customer)->reference;
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
