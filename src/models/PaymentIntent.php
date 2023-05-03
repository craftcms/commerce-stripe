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
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\records\PaymentIntent as PaymentIntentRecord;
use craft\elements\User;
use craft\validators\UniqueValidator;

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
     * @var int|null Payment Intent ID
     */
    public ?int $id = null;

    /**
     * @var int|null The Stripe Customer ID
     */
    public ?int $customerId = null;

    /**
     * @var int|null The gateway ID.
     */
    public ?int $gatewayId = null;

    /**
     * @var int|null The order ID.
     */
    public ?int $orderId = null;

    /**
     * @var string|null The Transaction Hash.
     */
    public ?string $transactionHash = null;

    /**
     * @var string|null Reference
     */
    public ?string $reference = null;

    /**
     * @var string|null Response data
     */
    public ?string $intentData = null;

    /**
     * @var User|null
     */
    private ?User $_user = null;

    /**
     * @var GatewayInterface|null
     */
    private ?GatewayInterface $_gateway = null;

    /**
     * @var Customer|null
     */
    private ?Customer $_customer = null;

    /**
     * @var Order|null
     */
    private ?Order $_order = null;

    /**
     * @var Transaction|null
     */
    private ?Transaction $_transaction = null;

    /**
     * Returns the customer identifier
     *
     * @return string
     */
    public function __toString()
    {
        return $this->reference ?? '';
    }

    /**
     * Returns the user element associated with this payment intent.
     *
     * @return User|null
     */
    public function getUser(): ?User
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
     * Returns the gateway associated with this payment intent.
     *
     * @return GatewayInterface|null
     * @throws \yii\base\InvalidConfigException
     */
    public function getGateway(): ?GatewayInterface
    {
        if (null === $this->_gateway) {
            $this->_gateway = Commerce::getInstance()->getGateways()->getGatewayById($this->gatewayId);
        }

        return $this->_gateway;
    }

    /**
     * Returns the user customer associated with this payment intent.
     *
     * @return Customer|null
     */
    public function getCustomer(): ?Customer
    {
        if (null === $this->_customer) {
            $this->_customer = StripePlugin::getInstance()->getCustomers()->getCustomerById($this->customerId);
        }

        return $this->_customer;
    }

    /**
     * Returns the gateway associated with this payment intent.
     *
     * @return Order|null
     * @throws \yii\base\InvalidConfigException
     */
    public function getOrder(): ?Order
    {
        if (null === $this->_order) {
            $this->_order = Commerce::getInstance()->getOrders()->getOrderById($this->orderId);
        }

        return $this->_order;
    }

    /**
     * Returns the transaction associated with this payment intent.
     *
     * @return Transaction|null
     * @throws \yii\base\InvalidConfigException
     */
    public function getTransaction(): ?Transaction
    {
        if (null === $this->_transaction) {
            $this->_transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($this->transactionHash);
        }

        return $this->_transaction;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['reference'], UniqueValidator::class, 'targetClass' => PaymentIntentRecord::class];
        $rules[] = [['gatewayId', 'customerId', 'reference', 'intentData', 'orderId', 'transactionHash'], 'required'];

        return $rules;
    }
}
