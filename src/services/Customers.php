<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\services;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\gateways\Gateway;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\errors\CustomerException;
use craft\commerce\stripe\models\Customer;
use craft\commerce\stripe\records\Customer as CustomerRecord;
use craft\db\Query;
use craft\elements\User;
use Stripe\Customer as StripeCustomer;
use Stripe\Stripe;
use yii\base\Component;
use yii\base\Exception;

/**
 * Customer service.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Customers extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns a customer by gateway and user
     *
     * @param int $gatewayId The stripe gateway
     * @param User $user The user
     *
     * @return Customer
     * @throws CustomerException
     */
    public function getCustomer(int $gatewayId, User $user): Customer
    {
        $result = $this->_createCustomerQuery()
            ->where(['userId' => $user->id, 'gatewayId' => $gatewayId])
            ->one();

        if ($result !== null) {
            return new Customer($result);
        }

        Stripe::setApiKey(Commerce::getInstance()->getGateways()->getGatewayById($gatewayId)->apiKey);
        Stripe::setAppInfo(StripePlugin::getInstance()->name, StripePlugin::getInstance()->version, StripePlugin::getInstance()->documentationUrl);
        Stripe::setApiVersion(Gateway::STRIPE_API_VERSION);

        /** @var StripeCustomer $stripeCustomer */
        $stripeCustomer = StripeCustomer::create([
            'description' => Craft::t('commerce-stripe', 'Customer for Craft user with ID {id}', ['id' => $user->id]),
            'email' => $user->email
        ]);

        $customer = new Customer([
            'userId' => $user->id,
            'gatewayId' => $gatewayId,
            'reference' => $stripeCustomer->id,
            'response' => $stripeCustomer->jsonSerialize()
        ]);

        if (!$this->saveCustomer($customer)) {
            throw new CustomerException('Could not save customer: ' . implode(', ', $customer->getErrorSummary(true)));
        }

        return $customer;
    }

    /**
     * Save a customer
     *
     * @param Customer $customer The customer being saved.
     * @return bool Whether the payment source was saved successfully
     * @throws Exception if payment source not found by id.
     */
    public function saveCustomer(Customer $customer): bool
    {
        if ($customer->id) {
            $record = CustomerRecord::findOne($customer->id);

            if (!$record) {
                throw new Exception(Craft::t('commerce-stripe', 'No customer exists with the ID “{id}”',
                    ['id' => $customer->id]));
            }
        } else {
            $record = new CustomerRecord();
        }

        $record->userId = $customer->userId;
        $record->gatewayId = $customer->gatewayId;
        $record->reference = $customer->reference;
        $record->response = $customer->response;

        $customer->validate();

        if (!$customer->hasErrors()) {
            // Save it!
            $record->save(false);

            // Now that we have a record ID, save it on the model
            $customer->id = $record->id;

            return true;
        }

        return false;
    }

    /**
     * Delete a customer by it's id.
     *
     * @param int $id The id
     *
     * @return bool
     * @throws \Throwable in case something went wrong when deleting.
     */
    public function deleteCustomerById($id): bool
    {
        $record = CustomerRecord::findOne($id);

        if ($record) {
            return (bool)$record->delete();
        }

        return false;
    }

    // Private methods
    // =========================================================================

    /**
     * Returns a Query object prepped for retrieving customers.
     *
     * @return Query The query object.
     */
    private function _createCustomerQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'gatewayId',
                'userId',
                'reference',
                'response',
            ])
            ->from(['{{%stripe_customers}}']);
    }

}
