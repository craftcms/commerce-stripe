<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe;

use CraftCms\Commerce\Payment\Events\PrimaryPaymentSourceUpdated;
use CraftCms\Commerce\Payment\PaymentSources;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps Commerce payment sources in sync with the payment methods of Stripe customers.
 */
#[Singleton]
class PaymentMethods
{
    /**
     * Creates or updates a payment source for every payment method of every customer in the gateway’s Stripe account.
     *
     * @return int The number of payment methods synced
     * @throws \Stripe\Exception\ApiErrorException
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException
     */
    public function syncAllPaymentMethods(Gateway $gateway): int
    {
        $count = 0;
        $client = $gateway->getStripeClient();
        $customers = $client->customers->all(['limit' => 100]);

        foreach ($customers->autoPagingIterator() as $customer) {
            foreach ($client->customers->allPaymentMethods($customer->id)->autoPagingIterator() as $paymentMethod) {
                // Uses the same lock as webhooks for the payment method.
                Cache::lock("commerceTransaction:{$paymentMethod->id}", 15)
                    ->block(15, fn() => $gateway->handlePaymentMethodUpdated($paymentMethod->toArray()));

                $count++;
            }
        }

        return $count;
    }

    /**
     * Makes a payment source the customer’s default payment method in Stripe when it becomes their primary payment
     * source in Commerce.
     */
    public function handlePrimaryPaymentSourceUpdated(PrimaryPaymentSourceUpdated $event): void
    {
        if (!$event->newPrimaryPaymentSourceId) {
            return;
        }

        $paymentSource = app(PaymentSources::class)->getPaymentSourceById($event->newPrimaryPaymentSourceId);
        $gateway = $paymentSource?->getGateway();

        if (!$gateway instanceof Gateway) {
            return;
        }

        $reference = app(Customers::class)->getCustomer($gateway->id, $event->customer)->reference;
        $gateway->setPaymentSourceAsDefault($reference, $paymentSource->token);
    }
}
