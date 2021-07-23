<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models;

use craft\base\Model;

/**
 * Settings model.
 *
 * @property bool $chargeInvoicesImmediately
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Settings extends Model
{
    /**
     * @var bool Whether to attempt to charge any created invoice immediately instead of waiting 1-2 hours.
     */
    public $chargeInvoicesImmediately = false;
}
