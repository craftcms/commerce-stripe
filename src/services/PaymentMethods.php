<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\services;

use craft\commerce\events\UpdatePrimaryPaymentSourceEvent;
use craft\commerce\models\PaymentSource;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\stripe\base\Gateway;
use craft\commerce\stripe\base\SubscriptionGateway;
use craft\commerce\stripe\Plugin;
use craft\commerce\stripe\Plugin as StripePlugin;

/**
 * Payment sources service.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 */
class PaymentMethods
{
    /**
     * @param Gateway $gateway
     * @return int Total synced payment methods
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function syncAllPaymentMethods(Gateway $gateway)
    {
        /** @var SubscriptionGateway $gateway */
        $count = 0;
        $customers = $gateway->getStripeClient()->customers->all([
            'limit' => 100,
        ]);

        foreach ($customers->autoPagingIterator() as $customer) {
            $stripePaymentMethods = $customer->allPaymentMethods(
                $customer->id,
                ['type' => 'card'],
            );

            foreach ($stripePaymentMethods as $stripePaymentMethod) {
                $gateway->handlePaymentMethodUpdated($stripePaymentMethod->toArray());
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param $gateway
     * @param $paymentMethodId
     * @return void
     * @throws \yii\base\InvalidConfigException
     */
    public function deletePaymentMethod(PaymentSource $paymentSource)
    {
        /** @var Gateway $gateway */
        $gateway = $paymentSource->getGateway();
        $stripeGatewayIds = StripePlugin::getInstance()->getStripeGateways()->pluck('id')->all();
        if (in_array($gateway->id, $stripeGatewayIds, false) && $paymentSource->token) {
            try {
                $method = $gateway->getStripeClient()->paymentMethods->retrieve($paymentSource->token);
                if($method->customer) {
                    $gateway->getStripeClient()->paymentMethods->detach($paymentSource->token);
                }
            } catch (\Exception $e) {
                // If the payment method doesn't exist, we don't need to do anything.
                return;
            }
        }
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
            $stripeCustomerReference = Plugin::getInstance()->getCustomers()->getCustomer($gateway->id, $event->customer)->reference;
            $gateway->setPaymentSourceAsDefault($stripeCustomerReference, $newPrimaryPaymentSource->token);
        }
    }
}
