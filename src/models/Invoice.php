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
     * @var int Payment source ID
     */
    public $id;

    /**
     * @var int The subscription Id
     */
    public $subscriptionId;

    /**
     * @var string The reference
     */
    public $reference;

    /**
     * @var mixed invoice data
     */
    public $invoiceData;

    /**
     * @var Subscription
     */
    private $_subscription;

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
     * @return Subscription|null
     */
    public function getSubscription()
    {
        if (null === $this->_subscription) {
            $this->_subscription = Subscription::find()->id($this->subscriptionId)->one();
        }

        return $this->_subscription;
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['subscriptionId', 'reference', 'invoiceData'], 'required']
        ];
    }
}
