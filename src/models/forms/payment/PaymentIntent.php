<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\models\forms\payment;

use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\stripe\Plugin;

/**
 * Charge Payment form model.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class PaymentIntent extends BasePaymentForm
{
    /**
     * Defaults to 'card'.
     *
     * @var string
     */
    public string $paymentFormType = 'card';

    /**
     * The payment method types allowed to be used. If null, then `automatic_payment_methods` is used.
     *
     * @var array|null $paymentMethodTypes
     */
    public ?array $paymentMethodTypes = null;

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
        // The legacy card process expects a payment method ID immediately
        return [
            [['paymentMethodId'], 'required', 'when' => function($model) {
                return $model->paymentFormType === 'card';
            }],
        ];
    }

    /**
     * @inheritdoc
     */
    public function populateFromPaymentSource(PaymentSource $paymentSource): void
    {
        $this->paymentMethodId = $paymentSource->token;

        $customer = Plugin::getInstance()->getCustomers()->getCustomer($paymentSource->gatewayId, $paymentSource->getCustomer());
        $this->customer = $customer->reference;
    }
}
