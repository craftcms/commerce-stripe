<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Database;

/**
 * The plugin’s database table names, without the table prefix.
 */
abstract class Table
{
    public const string CUSTOMERS = 'stripe_customers';

    /**
     * Invoices of Commerce subscriptions, which Commerce 6 no longer supports. The table is kept so the data isn’t lost.
     */
    public const string INVOICES = 'stripe_invoices';

    public const string PAYMENTINTENTS = 'stripe_paymentintents';
}
