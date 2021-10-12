<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\web\assets\chargeform;

use yii\web\AssetBundle;
use yii\web\JqueryAsset;

/**
 * Asset bundle for the Payment Form
 */
class ChargeFormAsset extends AssetBundle
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

        $this->depends = [
            JqueryAsset::class,
        ];

        parent::init();
    }
}
