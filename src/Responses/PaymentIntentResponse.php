<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * Wraps a Stripe payment intent or refund, or the error Stripe returned instead of one.
 */
class PaymentIntentResponse implements RequestResponseInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        protected array $data,
    ) {
    }

    public function isSuccessful(): bool
    {
        // A pending refund isn’t successful yet.
        if ($this->isRefund() && ($this->data['status'] ?? null) === Refund::STATUS_PENDING) {
            return false;
        }

        return in_array($this->data['status'] ?? null, ['succeeded', 'requires_capture'], true);
    }

    public function isProcessing(): bool
    {
        if ($this->isRefund() && ($this->data['status'] ?? null) === Refund::STATUS_PENDING) {
            return true;
        }

        if (($this->data['next_action'] ?? null) === null && ($this->data['status'] ?? null) === 'processing') {
            return true;
        }

        // Payment methods that are paid later, like bank transfers and ACH micro-deposit verification
        return ($this->data['object'] ?? null) === PaymentIntent::OBJECT_NAME
            && in_array($this->data['next_action']['type'] ?? null, ['display_bank_transfer_instructions', 'verify_with_microdeposits'], true);
    }

    public function isRedirect(): bool
    {
        if (!empty($this->data['last_payment_error']) && empty($this->data['next_action'])) {
            return false;
        }

        if (in_array($this->data['status'] ?? null, ['requires_payment_method', 'requires_confirmation'], true)) {
            return true;
        }

        return isset($this->data['next_action']['redirect_to_url']);
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectData(): array
    {
        if (in_array($this->data['status'] ?? null, ['requires_payment_method', 'requires_confirmation'], true)) {
            return [
                'client_secret' => $this->data['client_secret'] ?? null,
                'payment_intent' => $this->data['id'] ?? null,
            ];
        }

        return [];
    }

    public function getRedirectUrl(): string
    {
        // A payment intent that still needs a payment method, or hasn’t been confirmed yet, is completed by Stripe.js
        // on the same page, using the client secret in the redirect data.
        if (in_array($this->data['status'] ?? null, ['requires_payment_method', 'requires_confirmation'], true)) {
            // Console requests get no URL. The redirect data has everything needed to continue.
            if (app()->runningInConsole()) {
                return '';
            }

            return request()->getRequestUri();
        }

        // A next action URL, such as a 3D Secure page
        return (string)($this->data['next_action']['redirect_to_url']['url'] ?? '');
    }

    public function getTransactionReference(): string
    {
        return (string)($this->data['id'] ?? '');
    }

    public function getCode(): string
    {
        if (empty($this->data['code'])) {
            return (string)($this->data['last_payment_error']['code'] ?? '');
        }

        return (string)$this->data['code'];
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        if ($this->isRefund() && ($this->data['status'] ?? null) === Refund::STATUS_PENDING) {
            return 'Refund processing';
        }

        if (!empty($this->data['message'])) {
            return (string)$this->data['message'];
        }

        if (!empty($this->data['last_payment_error'])) {
            if (($this->data['last_payment_error']['code'] ?? null) === 'payment_intent_authentication_failure') {
                return 'The provided payment method has failed authentication.';
            }

            return (string)($this->data['last_payment_error']['message'] ?? '');
        }

        return '';
    }

    protected function isRefund(): bool
    {
        return ($this->data['object'] ?? null) === Refund::OBJECT_NAME;
    }
}
