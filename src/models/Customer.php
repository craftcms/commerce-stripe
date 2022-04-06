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
use yii\base\InvalidConfigException;

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
     * @var mixed Response data
     */
    public mixed $response = null;

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
    public function getUser(): ?User
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
     * @throws InvalidConfigException
     */
    public function getGateway(): ?GatewayInterface
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
        $rules[] = [['reference'], UniqueValidator::class, 'targetClass' => CustomerRecord::class];
        $rules[] = [['gatewayId', 'userId', 'reference', 'response'], 'required'];

        return $rules;
    }
}
