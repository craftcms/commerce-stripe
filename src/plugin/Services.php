<?php

namespace craft\commerce\stripe\plugin;

use craft\commerce\stripe\services\Customers;

/**
 * Trait Services
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  1.0
 */
trait Services
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the address service
     *
     * @return Customers The customers service
     */
    public function getCustomers(): Customers
    {
        return $this->get('customers');
    }

    // Private Methods
    // =========================================================================

    /**
     * Set the components of the commerce plugin
     *
     * @return void
     */
    private function _setPluginComponents()
    {
        $this->setComponents([
            'customers' => Customers::class,
        ]);
    }
}
