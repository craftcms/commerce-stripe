<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Forms;

use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;
use CraftCms\CommerceStripe\Customers;
use CraftCms\CommerceStripe\Gateway;
use Override;

/**
 * The payment form posted to `commerce/payments/pay` and `commerce/payment-sources/add`.
 */
class PaymentIntentForm extends BasePaymentForm
{
    /**
     * @var string `elements` or `checkout`
     */
    public string $paymentFormType = Gateway::PAYMENT_FORM_TYPE_ELEMENTS;

    /**
     * @var string|null The Stripe customer ID
     */
    public ?string $customer = null;

    /**
     * @var string|null The Stripe payment method ID, when paying with a saved payment source or a custom payment form
     */
    public ?string $paymentMethodId = null;

    /**
     * @var string|null The Stripe ConfirmationToken ID, when confirming the payment intent on the server
     */
    public ?string $confirmationToken = null;

    #[Override]
    public function populateFromPaymentSource(PaymentSource $paymentSource): void
    {
        $this->paymentMethodId = $paymentSource->token;

        if ($user = $paymentSource->getCustomer()) {
            $this->customer = app(Customers::class)->getCustomer($paymentSource->gatewayId, $user)->reference;
        }
    }
}
