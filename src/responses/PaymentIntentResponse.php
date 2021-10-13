<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\responses;

use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\NotImplementedException;
use Stripe\Refund;

class PaymentIntentResponse implements RequestResponseInterface
{
    /**
     * @var array the response data
     */
    protected $data = [];

    /**
     * Response constructor.
     *
     * @param array $data
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * @inheritdoc
     */
    public function isSuccessful(): bool
    {
        if (array_key_exists('object', $this->data) && $this->data['object'] == Refund::OBJECT_NAME) {
            if (array_key_exists('status', $this->data) && in_array($this->data['status'], [Refund::STATUS_PENDING], true)) {
                return false;
            }
        }

        return array_key_exists('status', $this->data) && in_array($this->data['status'], ['succeeded', 'requires_capture'], true);
    }

    /**
     * @inheritdoc
     */
    public function isProcessing(): bool
    {
        if (array_key_exists('object', $this->data) && $this->data['object'] == Refund::OBJECT_NAME) {
            if (array_key_exists('status', $this->data) && in_array($this->data['status'], [Refund::STATUS_PENDING], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function isRedirect(): bool
    {
        return array_key_exists('next_action', $this->data) && is_array($this->data['next_action']) && array_key_exists('redirect_to_url', $this->data['next_action']);
    }

    /**
     * @inheritdoc
     */
    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    /**
     * @inheritdoc
     */
    public function getRedirectData(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getRedirectUrl(): string
    {
        return $this->data['next_action']['redirect_to_url']['url'] ?? '';
    }

    /**
     * @inheritdoc
     */
    public function getTransactionReference(): string
    {
        if (empty($this->data)) {
            return '';
        }

        return (string)$this->data['id'];
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        if (empty($this->data['code'])) {
            if (!empty($this->data['last_payment_error'])) {
                return $this->data['last_payment_error']['code'];
            }

            return '';
        }

        return $this->data['code'];
    }

    /**
     * @inheritdoc
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @inheritdoc
     */
    public function getMessage(): string
    {
        if (array_key_exists('object', $this->data) && $this->data['object'] == Refund::OBJECT_NAME) {
            if (array_key_exists('status', $this->data) && in_array($this->data['status'], [Refund::STATUS_PENDING], true)) {
                return 'Refund processing';
            }
        }

        if (empty($this->data['message'])) {
            if (!empty($this->data['last_payment_error'])) {
                if ($this->data['last_payment_error']['code'] === 'payment_intent_authentication_failure') {
                    return 'The provided payment method has failed authentication.';
                }

                return $this->data['last_payment_error']['message'];
            }

            return '';
        }

        return $this->data['message'];
    }

    /**
     * @inheritdoc
     */
    public function redirect()
    {
        throw new NotImplementedException('Redirecting directly is not implemented for this gateway.');
    }

}
