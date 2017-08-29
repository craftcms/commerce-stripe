<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\commerce\stripe;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle for the Dashboard
 */
class StripePaymentBundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = '@craft/commerce/stripe/resources';

        $this->css = [
            'css/paymentForm.css',
        ];

        $this->js = [
            'js/paymentForm.js',
        ];

        parent::init();
    }
}
