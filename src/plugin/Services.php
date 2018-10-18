<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\plugin;

use craft\commerce\stripe\services\Customers;
use craft\commerce\stripe\services\Invoices;

/**
 * Trait Services
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
trait Services
{
    // Public Methods
    // =========================================================================

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

    // Private Methods
    // =========================================================================

    /**
     * Set the components of the commerce plugin
     */
    private function _setPluginComponents()
    {
        $this->setComponents([
            'customers' => Customers::class,
            'invoices' => Invoices::class,
        ]);
    }
}
