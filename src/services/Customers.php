<?php

namespace craft\commerce\stripe\services;

use Craft;
use craft\commerce\stripe\models\Customer;
use craft\commerce\stripe\records\Customer as CustomerRecord;
use craft\db\Query;
use yii\base\Component;
use yii\base\Exception;

/**
 * Customer service.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  2.0
 */
class Customers extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns a customer by gateway and user id.
     *
     * @param int $gatewayId The stripe gateway
     * @param int $userId    The user id.
     *
     * @return Customer|null
     */
    public function getCustomer(int $gatewayId, int $userId)
    {
        $result = $this->_createCustomerQuery()
            ->where(['userId' => $userId, 'gatewayId' => $gatewayId])
            ->one();

        return $result ? new Customer($result) : null;
    }

    /**
     * Save a customer
     *
     * @param Customer $customer The customer being saved.
     *
     * @return bool Whether the payment source was saved successfully
     * @throws Exception if payment source not found by id.
     */
    public function saveCustomer(Customer $customer)
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
