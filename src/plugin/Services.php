<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\plugin;

use craft\commerce\stripe\services\Customers;
use craft\commerce\stripe\services\Invoices;
use craft\commerce\stripe\services\PaymentIntents;
use craft\commerce\stripe\services\PaymentMethods;

/**
 * Trait Services
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
trait Services
{
    /**
     * Returns the customers service
     *
     * @return Customers The customers service
     */
    public function getCustomers(): Customers
    {
        return $this->get('customers');
    }

    /**
     * Returns the invoices service
     *
     * @return Invoices The invoices service
     */
    public function getInvoices(): Invoices
    {
        return $this->get('invoices');
    }

    /**
     * Returns the payment intents service
     *
     * @return PaymentIntents The payment intents service
     */
    public function getPaymentIntents(): PaymentIntents
    {
        return $this->get('paymentIntents');
    }

    /**
     * Returns the payment methods service
     *
     * @return PaymentMethods The payment sources service
     */
    public function getPaymentMethods(): PaymentMethods
    {
        return $this->get('paymentMethods');
    }
}
