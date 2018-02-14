<?php

namespace craft\commerce\stripe\events;

use craft\commerce\stripe\models\Invoice;
use yii\base\Event;

/**
 * Class CreateInvoiceEvent
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  1.0
 */
class CreateInvoiceEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var Invoice The invoice being saved.
     */
    public $invoice;
}
