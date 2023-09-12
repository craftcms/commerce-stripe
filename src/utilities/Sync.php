<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\stripe\utilities;

use Craft;
use craft\base\Utility;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\stripe\base\Gateway;

/**
 * Sync class offers the Stripe Sync utilities.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 */
class Sync extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Stripe Sync');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'stripe-sync';
    }

    /**
     * @inheritdoc
     */
    public static function iconPath(): ?string
    {
        return Craft::getAlias('@vendor') . '/craftcms/stripe/src/icon-mask.svg';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $view = Craft::$app->getView();

        $stripeGatewayOptions = collect(CommercePlugin::getInstance()->getGateways()->getAllGateways())->where(function($gateway) {
            return $gateway instanceof Gateway;
        })->map(function($gateway) {
            /** @var Gateway $gateway */
            return [
                'value' => $gateway->id,
                'label' => $gateway->name,
            ];
        });
        return $view->renderTemplate('commerce-stripe/utilities/_sync.twig', compact('stripeGatewayOptions'));
    }
}
