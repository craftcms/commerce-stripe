<?php

namespace craft\commerce\stripe;

use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\gateways\Stripe;
use craft\events\RegisterComponentTypesEvent;


/**
 * Plugin represents the Stripe integration plugin.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  1.0
 */
class Plugin extends \craft\base\Plugin
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Commerce::getInstance()->getGateways()->on('registerGatewayTypes', function(RegisterComponentTypesEvent $event) {
            $event->types[] = Stripe::class;
        });
    }
}
