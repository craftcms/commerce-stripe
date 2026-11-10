<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Responses;

use Override;

/**
 * Response for a payment intent that was confirmed on the server with a ConfirmationToken.
 *
 * A payment intent that still needs customer action, or is processing, is treated as a redirect so the parent
 * transaction stays in the `redirect` status. The customer is then sent to `commerce/payments/complete-payment` (after
 * Stripe.js handles any next action), which completes the payment and the order the same way as the client-side
 * confirmation flow.
 */
class ServerConfirmedPaymentIntentResponse extends PaymentIntentResponse
{
    /**
     * @param array<string, mixed> $data
     * @param string $completePaymentUrl The URL to send the customer to once any next action is complete
     */
    public function __construct(
        array $data,
        protected string $completePaymentUrl,
    ) {
        parent::__construct($data);
    }

    #[Override]
    public function isProcessing(): bool
    {
        return false;
    }

    #[Override]
    public function isRedirect(): bool
    {
        return in_array($this->data['status'] ?? null, ['requires_action', 'processing'], true);
    }

    #[Override]
    public function getRedirectUrl(): string
    {
        return (string)($this->data['next_action']['redirect_to_url']['url'] ?? $this->completePaymentUrl);
    }

    #[Override]
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
