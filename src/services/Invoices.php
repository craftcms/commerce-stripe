<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

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
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Invoices extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SaveInvoiceEvent The event that is triggered when an invoice is saved.
     * You may set [[SaveInvoiceEvent::isValid]] to `false` to prevent the invoice from being saved
     *
     * Plugins can get notified whenever a new invoice for a subscription is being saved.
     *
     * ```php
     * use craft\commerce\stripe\events\SaveInvoiceEvent;
     * use craft\commerce\stripe\services\Invoices;
     * use yii\base\Event;
     *
     * Event::on(Invoices::class, Invoices::EVENT_BEFORE_SAVE_INVOICE, function(SaveInvoiceEvent $e) {
     *     $stripeInvoiceId = $e->invoice->invoiceId;
     *     // Do something with the data...
     * });
     * ```
     */
    const EVENT_BEFORE_SAVE_INVOICE = 'beforeSaveInvoice';

    /**
     * @event SaveInvoiceEvent The event that is triggered when an invoice is saved.
     *
     * Plugins can get notified whenever a new invoice for a subscription is being saved.
     *
     * ```php
     * use craft\commerce\stripe\events\SaveInvoiceEvent;
     * use craft\commerce\stripe\services\Invoices;
     * use yii\base\Event;
     *
     * Event::on(Invoices::class, Invoices::EVENT_SAVE_INVOICE, function(SaveInvoiceEvent $e) {
     *     $stripeInvoiceId = $e->invoice->invoiceId;
     *     // Do something with the data...
     * });
     * ```
     */
    const EVENT_SAVE_INVOICE = 'afterSaveInvoice';

    // Public Methods
    // =========================================================================

    /**
     * Returns a customer by gateway and user id.
     *
     * @param int $subscriptionId The subscription id.
     * @return Invoice[]
     */
    public function getSubscriptionInvoices(int $subscriptionId): array
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
     * Save an invoice.
     *
     * @param Invoice $invoice The invoice being saved.
     * @return bool Whether the invoice was saved successfully
     * @throws Exception if invoice not found by id.
     */
    public function saveInvoice(Invoice $invoice): bool
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
            $event = new SaveInvoiceEvent(['invoice' => $invoice]);

            // Fire a 'beforeSaveInvoice' event.
            $this->trigger(self::EVENT_BEFORE_SAVE_INVOICE, $event);

            if (!$event->isValid) {
                return false;
            }

            $record->save(false);
            $invoice->id = $record->id;

            // Fire a 'afterSaveInvoice' event.
            if ($this->hasEventHandlers(self::EVENT_SAVE_INVOICE)) {
                $this->trigger(self::EVENT_SAVE_INVOICE, new SaveInvoiceEvent(['invoice' => $invoice]));
            }

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
