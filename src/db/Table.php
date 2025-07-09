<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\db;

/**
 * Table Class
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 5.1
 */
abstract class Table
{
    public const CUSTOMERS = '{{%stripe_customers}}';
    public const INVOICES = '{{%stripe_invoices}}';
    public const PAYMENTINTENTS = '{{%stripe_paymentintents}}';
}
