<?php

namespace craft\commerce\stripe\records;

use craft\commerce\records\Gateway;
use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Payment source record.
 *
 * @property int               $id
 * @property int               $userId
 * @property int               $gatewayId
 * @property string            $customerId
 * @property string            $response
 * @property Gateway           $gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  2.0
 */
class Customer extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%stripe_customers}}';
    }

    /**
     * Return the payment source's gateway
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getGateway(): ActiveQueryInterface
    {
        return $this->hasOne(Gateway::class, ['gatewayId' => 'id']);
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {

        return [
            [['customerId'], 'unique', 'targetAttribute' => ['gatewayId', 'customerId']],
            [['gatewayId', 'userId', 'customerId', 'response'], 'required']
        ];

    }
}
