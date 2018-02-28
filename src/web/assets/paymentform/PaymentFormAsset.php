<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.com/license
 */

namespace craft\commerce\stripe\web\assets\paymentform;

use craft\web\AssetBundle;

/**
 * Asset bundle for the Payment Form
 */
class PaymentFormAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = __DIR__;

        $this->css = [
            'css/paymentForm.css',
        ];

        $this->js = [
            'js/paymentForm.js',
        ];

        parent::init();
    }
}
