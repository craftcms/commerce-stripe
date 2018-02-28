<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\records;

use craft\commerce\records\Gateway;
use craft\db\ActiveRecord;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * Payment source record.
 *
 * @property int $id
 * @property int $userId
 * @property int $gatewayId
 * @property string $reference
 * @property string $response
 * @property Gateway $gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
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
     * Return the payment source's owner user.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
