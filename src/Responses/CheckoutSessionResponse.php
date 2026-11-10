<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;
use RuntimeException;

/**
 * Wraps a Stripe Checkout session. The customer is always redirected to the session’s hosted payment page.
 */
class CheckoutSessionResponse implements RequestResponseInterface
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
        return false;
    }

    public function isProcessing(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return true;
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    /**
     * @throws RuntimeException if the session has no URL
     */
    public function getRedirectUrl(): string
    {
        if (!isset($this->data['url'])) {
            throw new RuntimeException('No url found in checkout session to redirect to');
        }

        return (string)$this->data['url'];
    }

    /**
     * @throws RuntimeException if the session has no ID
     */
    public function getTransactionReference(): string
    {
        if (!isset($this->data['id'])) {
            throw new RuntimeException('No checkout session ID found');
        }

        return (string)$this->data['id'];
    }

    public function getCode(): string
    {
        return '';
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        return '';
    }
}
