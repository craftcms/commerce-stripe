<?php

namespace craft\commerce\stripe\services;

use Craft;
use craft\commerce\stripe\events\SaveInvoiceEvent;
use craft\commerce\stripe\models\Invoice;
use craft\commerce\stripe\records\Invoice as InvoiceRecord;
use craft\db\Query;
use craft\helpers\Json;
use yii\base\Component;
use yii\base\Exception;

/**
 * Invoice service.
 **
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  2.0
 */
class Invoices extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SaveInvoiceEvent The event that is triggered when an invoice is saved
     */
    const EVENT_SAVE_INVOICE = 'afterSaveInvoice';

    // Public Methods
    // =========================================================================

    /**
     * Returns a customer by gateway and user id.
     *
     * @param int $subscriptionId The subscription id.
     *
     * @return Invoice[]
     */
    public function getSubscriptionInvoices(int $subscriptionId)
    {
        $results = $this->_createInvoiceQuery()
            ->where(['subscriptionId' => $subscriptionId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        $invoices = [];

        foreach ($results as $result) {
            $result['invoiceData'] = Json::decodeIfJson($result['invoiceData']);
            $invoices[] = new Invoice($result);
        }

        return $invoices;
    }

    /**
     * Save a customer
     *
     * @param Invoice $invoice The invoice being saved.
     *
     * @return bool Whether the invoice was saved successfully
     * @throws Exception if invoice not found by id.
     */
    public function saveInvoice(Invoice $invoice)
    {
        if ($invoice->id) {
            $record = InvoiceRecord::findOne($invoice->id);

            if (!$record) {
                throw new Exception(Craft::t('commerce-stripe', 'No invoice exists with the ID “{id}”', ['id' => $invoice->id]));
            }
        } else {
            $record = new InvoiceRecord();
        }

        // Only allow this for new invoices
        if (!$invoice->id) {
            $record->reference = $invoice->reference;
            $record->subscriptionId = $invoice->subscriptionId;
        }

        $record->invoiceData = $invoice->invoiceData;

        if ($invoice->validate()) {
            $record->save(false);
            $invoice->id = $record->id;

            $this->trigger(self::EVENT_SAVE_INVOICE, new SaveInvoiceEvent(['invoice' => $invoice]));

            return true;
        }

        return false;
    }

    // Private methods
    // =========================================================================

    /**
     * Returns a Query object prepped for retrieving invoices.
     *
     * @return Query The query object.
     */
    private function _createInvoiceQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'reference',
                'subscriptionId',
                'invoiceData',
            ])
            ->from(['{{%stripe_invoices}}']);
    }

}
