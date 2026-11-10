<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\responses;

/**
 * Response for a payment intent that was confirmed server-side with a ConfirmationToken.
 *
 * Any payment intent that still needs customer action, or is processing, is treated as a redirect so the parent
 * transaction stays in the `redirect` status. The customer is then sent to `commerce/payments/complete-payment`
 * (after Stripe.js handles any next action), which completes the payment and the order the same way as the
 * client-side confirmation flow.
 *
 * @since 6.0.0
 */
class ServerConfirmedPaymentIntentResponse extends PaymentIntentResponse
{
    /**
     * @var string The URL to send the customer to once any next action is complete.
     */
    protected string $completePaymentUrl;

    /**
     * @param array $data
     * @param string $completePaymentUrl
     */
    public function __construct(array $data, string $completePaymentUrl)
    {
        parent::__construct($data);
        $this->completePaymentUrl = $completePaymentUrl;
    }

    /**
     * @inheritdoc
     */
    public function isProcessing(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function isRedirect(): bool
    {
        return in_array($this->data['status'] ?? null, ['requires_action', 'processing'], true);
    }

    /**
     * @inheritdoc
     */
    public function getRedirectUrl(): string
    {
        return $this->data['next_action']['redirect_to_url']['url'] ?? $this->completePaymentUrl;
    }

    /**
     * @inheritdoc
     */
    public function getRedirectData(): array
    {
        if (!$this->isRedirect()) {
            return [];
        }

        return [
            'client_secret' => $this->data['client_secret'] ?? null,
            'payment_intent' => $this->data['id'] ?? null,
            'status' => $this->data['status'],
            'complete_payment_url' => $this->completePaymentUrl,
        ];
    }
}
