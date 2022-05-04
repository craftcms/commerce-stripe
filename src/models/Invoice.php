<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models;

use craft\commerce\base\Model;
use craft\commerce\elements\Subscription;

/**
 * Stripe Payment form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Invoice extends Model
{
    /**
     * @var int|null Payment source ID
     */
    public ?int $id = null;

    /**
     * @var int|null The subscription ID
     */
    public ?int $subscriptionId = null;

    /**
     * @var string|null The reference
     */
    public ?string $reference = null;

    /**
     * @var mixed invoice data
     */
    public mixed $invoiceData;

    /**
     * @var Subscription|null
     */
    private ?Subscription $_subscription = null;

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
     * @return Subscription|null
     */
    public function getSubscription(): ?Subscription
    {
        if (null === $this->_subscription) {
            $this->_subscription = Subscription::find()->id($this->subscriptionId)->one();
        }

        return $this->_subscription;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['subscriptionId', 'reference', 'invoiceData'], 'required'],
        ];
    }
}
