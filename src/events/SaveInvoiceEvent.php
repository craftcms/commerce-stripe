<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use craft\commerce\stripe\models\Invoice;
use craft\events\CancelableEvent;

/**
 * Class SaveInvoiceEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class SaveInvoiceEvent extends CancelableEvent
{
    // Properties
    // =========================================================================

    /**
     * @var Invoice The invoice being saved.
     */
    public $invoice;
}
