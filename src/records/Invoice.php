<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\records;

use craft\commerce\records\Subscription;
use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Invoice record.
 *
 * @property int $id
 * @property string $reference
 * @property int $subscriptionId
 * @property string $invoiceData
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Invoice extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%stripe_invoices}}';
    }

    /**
     * Return the subscription
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getSubscription(): ActiveQueryInterface
    {
        return $this->hasOne(Subscription::class, ['subscriptionId' => 'id']);
    }
}
