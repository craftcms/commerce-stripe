<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models;

use Craft;
use craft\commerce\base\GatewayInterface;
use craft\commerce\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\records\Customer as CustomerRecord;
use craft\elements\User;

/**
 * Stripe Payment Intent model
 *
 * @property GatewayInterface $gateway
 * @property User $user
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class PaymentIntent extends Model
{
    /**
     * @var int Payment Intent ID
     */
    public $id;

    /**
     * @var int The Stripe Customer ID
     */
    public $customerId;

    /**
     * @var int The gateway ID.
     */
    public $gatewayId;

    /**
     * @var int The order ID.
     */
    public $orderId;

    /**
     * @var string Reference
     */
    public $reference;

    /**
     * @var string Response data
     */
    public $intentData;

    /**
     * @var User|null
     */
    private $_user;

    /**
     * @var GatewayInterface|null
     */
    private $_gateway;

    /**
     * @var Customer|null
     */
    private $_customer;

    /**
     * @var Order|null
     */
    private $_order;

    /**
     * Returns the customer identifier
     *
     * @return string
     */
    public function __toString()
    {
        return $this->reference;
    }

    /**
     * Returns the user element associated with this customer.
     *
     * @return User|null
     */
    public function getUser()
    {
        if (null === $this->_user) {
            $customer = $this->getCustomer();
            if ($customer) {
                $this->_user = Craft::$app->getUsers()->getUserById($customer->userId);
            }
        }

        return $this->_user;
    }

    /**
     * Returns the gateway associated with this customer.
     *
     * @return GatewayInterface|null
     */
    public function getGateway()
    {
        if (null === $this->_gateway) {
            $this->_gateway = Commerce::getInstance()->getGateways()->getGatewayById($this->gatewayId);
        }

        return $this->_gateway;
    }

    /**
     * Returns the user element associated with this customer.
     *
     * @return Customer|null
     */
    public function getCustomer()
    {
        if (null === $this->_customer) {
            $this->_customer = StripePlugin::getInstance()->getCustomers()->getCustomerById($this->customerId);
        }

        return $this->_customer;
    }

    /**
     * Returns the gateway associated with this customer.
     *
     * @return Order|null
     */
    public function getOrder()
    {
        if (null === $this->_order) {
            $this->_order = Commerce::getInstance()->getOrders()->getOrderById($this->orderId);
        }

        return $this->_order;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['reference'], 'unique', 'targetAttribute' => ['gatewayId', 'reference'], 'targetClass' => CustomerRecord::class],
            [['gatewayId', 'customerId', 'reference', 'intentData', 'orderId'], 'required'],
        ];
    }
}
