<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\services;

use craft\commerce\models\PaymentSource;
use craft\commerce\Plugin;
use craft\commerce\stripe\base\Gateway;
use craft\commerce\stripe\base\SubscriptionGateway;
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
            $gateway->getStripeClient()->paymentMethods->detach($paymentSource->token);
        }
    }
}
