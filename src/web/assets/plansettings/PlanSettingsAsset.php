<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\web\assets\plansettings;

use yii\web\AssetBundle;
use yii\web\JqueryAsset;

/**
 * Asset bundle for editing Craft subscription plans
 */
class PlanSettingsAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = __DIR__;

        $this->js = [
            'js/planSettings.js',
        ];

        $this->depends = [
            JqueryAsset::class,
        ];

        parent::init();
    }
}
