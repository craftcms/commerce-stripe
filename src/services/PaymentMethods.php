<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\services;

use craft\commerce\events\UpdatePrimaryPaymentSourceEvent;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\stripe\base\Gateway;
use craft\commerce\stripe\base\SubscriptionGateway;
use craft\commerce\stripe\Plugin;
use Craft;

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

                $lockName = "commerceTransaction:{$stripePaymentMethod['id']}";

                if (!Craft::$app->getMutex()->acquire($lockName, 5)) {
                    throw new Exception("Unable to acquire mutex lock: $lockName");
                }

                $gateway->handlePaymentMethodUpdated($stripePaymentMethod->toArray());

                Craft::$app->getMutex()->release($lockName);

                $count++;
            }
        }

        return $count;
    }

    /**
     * Whenever a payment source is set as primary in Commerce, lets make it the primary in the gateway too .
     *
     * @param UpdatePrimaryPaymentSourceEvent $event
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
