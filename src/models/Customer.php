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
     * @var int|null Customer ID
     */
    public ?int $id = null;

    /**
     * @var int|null The user ID
     */
    public ?int $userId = null;

    /**
     * @var int|null The gateway ID.
     */
    public ?int $gatewayId = null;

    /**
     * @var string|null Reference
     */
    public ?string $reference = null;

    /**
     * @var array|null Response data
     */
    public ?array $response = null;

    /**
     * @var User|null $_user
     */
    private ?User $_user = null;

    /**
     * @var GatewayInterface|null $_user
     */
    private ?GatewayInterface $_gateway = null;

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
     * @throws \yii\base\InvalidConfigException
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
    public function rules(): array
    {
        return [
            [['reference'], 'unique', 'targetAttribute' => ['gatewayId', 'reference'], 'targetClass' => CustomerRecord::class],
            [['gatewayId', 'userId', 'reference', 'response'], 'required'],
        ];
    }
}
