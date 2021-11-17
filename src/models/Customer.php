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
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\records\Customer as CustomerRecord;
use craft\elements\User;
use craft\validators\UniqueValidator;

/**
 * Stripe customer model
 *
 * @property GatewayInterface $gateway
 * @property User $user
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Customer extends Model
{
    /**
     * @var int Customer ID
     */
    public $id;

    /**
     * @var int The user ID
     */
    public $userId;

    /**
     * @var int The gateway ID.
     */
    public $gatewayId;

    /**
     * @var string Reference
     */
    public $reference;

    /**
     * @var string Response data
     */
    public $response;

    /**
     * @var User|null $_user
     */
    private $_user;

    /**
     * @var GatewayInterface|null $_user
     */
    private $_gateway;

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
            $this->_user = Craft::$app->getUsers()->getUserById($this->userId);
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
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['gatewayId', 'reference'], UniqueValidator::class, 'targetClass' => CustomerRecord::class];
        $rules[] = [['gatewayId', 'userId', 'reference', 'response'], 'required'];

        return $rules;
    }
}
