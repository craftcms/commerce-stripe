<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\events;

use yii\base\Event;

/**
 * Class CreateInvoiceEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class CreateInvoiceEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var array The invoice data.
     */
    public $invoiceData;
}
