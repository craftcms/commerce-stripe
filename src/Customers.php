<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe;

use CraftCms\Cms\User\Elements\User;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\CommerceStripe\Events\CustomerCreating;
use CraftCms\CommerceStripe\Exceptions\CustomerException;
use CraftCms\CommerceStripe\Models\Customer;
use Illuminate\Container\Attributes\Singleton;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Links Craft users to Stripe customers.
 */
#[Singleton]
class Customers
{
    /**
     * Returns the Stripe customer for a user and gateway, creating it in Stripe if it doesn’t exist yet.
     *
     * @throws CustomerException if the gateway isn’t a Stripe gateway, or the customer couldn’t be saved
     * @throws \Stripe\Exception\ApiErrorException if Stripe couldn’t create the customer
     */
    public function getCustomer(int $gatewayId, User $user): Customer
    {
        $customer = Customer::query()
            ->where('userId', $user->id)
            ->where('gatewayId', $gatewayId)
            ->first();

        if ($customer) {
            return $customer;
        }

        $gateway = app(Gateways::class)->getGatewayById($gatewayId);

        if (!$gateway instanceof Gateway) {
            throw new CustomerException("Gateway $gatewayId is not a Stripe gateway.");
        }

        $event = new CustomerCreating($gateway, $user, [
            'description' => t('Customer for Craft user with ID {id}', ['id' => $user->id], category: 'commerce-stripe'),
            'name' => $user->fullName,
            'email' => $user->email,
            'metadata' => [
                'craft_user_id' => $user->id,
            ],
        ]);
        event($event);

        $stripeCustomer = $gateway->getStripeClient()->customers->create($event->customer);

        try {
            return Customer::query()->create([
                'userId' => $user->id,
                'gatewayId' => $gatewayId,
                'reference' => $stripeCustomer->id,
                'response' => $stripeCustomer->toArray(),
            ]);
        } catch (Throwable $exception) {
            throw new CustomerException('Could not save customer: ' . $exception->getMessage(), previous: $exception);
        }
    }

    public function getCustomerById(int $id, ?int $gatewayId = null): ?Customer
    {
        return Customer::query()
            ->whereKey($id)
            ->when($gatewayId, fn($query) => $query->where('gatewayId', $gatewayId))
            ->first();
    }

    public function getCustomerByUserId(int $id): ?Customer
    {
        return Customer::query()->where('userId', $id)->first();
    }

    /**
     * Returns a customer by its Stripe customer ID.
     */
    public function getCustomerByReference(string $reference, ?int $gatewayId = null): ?Customer
    {
        return Customer::query()
            ->where('reference', $reference)
            ->when($gatewayId, fn($query) => $query->where('gatewayId', $gatewayId))
            ->first();
    }

    /**
     * Saves a customer.
     */
    public function saveCustomer(Customer $customer): bool
    {
        return $customer->save();
    }

    public function deleteCustomerById(int $id): bool
    {
        return (bool)Customer::query()->whereKey($id)->delete();
    }
}
