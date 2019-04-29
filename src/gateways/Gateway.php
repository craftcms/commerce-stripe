<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\stripe\base\SubscriptionGateway as BaseGateway;
use craft\commerce\stripe\events\ReceiveWebhookEvent;
use craft\helpers\Json;
use craft\web\Response as WebResponse;
use Stripe\Webhook;

/**
 * This class represents the Stripe Charge gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Gateway extends BaseGateway
{

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce-stripe', 'Stripe Charge');
    }
}
