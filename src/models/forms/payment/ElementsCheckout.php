<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models\forms\payment;

use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;

/**
 * Checkout Payment form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 */
class ElementsCheckout extends BasePaymentForm
{
    /**
     * @var string
     */
    public string $paymentFormType = 'elements';

    /**
     * @inheritdoc
     */
    public function populateFromPaymentSource(PaymentSource $paymentSource): void
    {
    }
}
