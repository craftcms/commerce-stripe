<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models\forms\payment;

use craft\commerce\models\payments\CreditCardPaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\stripe\Plugin;

/**
 * Charge Payment form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class PaymentIntent extends CreditCardPaymentForm
{
    /**
     * @var string|null $customer the Stripe customer token.
     */
    public ?string $customer = null;

    /**
     * @var string|null $paymentMethodId the Stripe payment method id.
     */
    public ?string $paymentMethodId = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['paymentMethodId'], 'required'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function populateFromPaymentSource(PaymentSource $paymentSource): void
    {
        $this->paymentMethodId = $paymentSource->token;

        $customer = Plugin::getInstance()->getCustomers()->getCustomer($paymentSource->gatewayId, $paymentSource->getUser());
        $this->customer = $customer->reference;
    }
}
