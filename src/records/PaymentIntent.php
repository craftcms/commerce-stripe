<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\records;

use craft\commerce\records\Gateway;
use craft\commerce\records\Order;
use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Payment source record.
 *
 * @property int $id
 * @property int $customerId
 * @property int $gatewayId
 * @property int $orderId
 * @property string $transactionHash
 * @property string $reference
 * @property string $intentData
 * @property Gateway $gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class PaymentIntent extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%stripe_paymentintents}}';
    }

    /**
     * Return the payment intent's gateway
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getGateway(): ActiveQueryInterface
    {
        return $this->hasOne(Gateway::class, ['gatewayId' => 'id']);
    }

    /**
     * Return the payment intent's order
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getorder(): ActiveQueryInterface
    {
        return $this->hasOne(Order::class, ['gatewayId' => 'id']);
    }

    /**
     * Return the payment intent's Stripe customer
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getCustomer(): ActiveQueryInterface
    {
        return $this->hasOne(Customer::class, ['customerId' => 'id']);
    }

}
