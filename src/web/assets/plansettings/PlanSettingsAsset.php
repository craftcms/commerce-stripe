<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\stripe\web\assets\plansettings;

use craft\web\AssetBundle;

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

        parent::init();
    }
}
