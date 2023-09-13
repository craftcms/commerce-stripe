<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use yii\base\Event;

/**
 * Class BuildSetupIntentRequestEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 */
class BuildSetupIntentRequestEvent extends Event
{
    /**
     * @var array The request being used
     */
    public array $request;
}
