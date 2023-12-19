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
    protected array $data = [];

    /**
     * Response constructor.
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @inheritdoc
     */
    public function isSuccessful(): bool
    {

        // If we are doing a refund and the status is pending, then we are not successful.
        if (array_key_exists('object', $this->data) && $this->data['object'] == Refund::OBJECT_NAME) {
            if (array_key_exists('status', $this->data) && in_array($this->data['status'], [Refund::STATUS_PENDING], true)) {
                return false;
            }
        }

        if (array_key_exists('status', $this->data) && $this->data['status'] === 'requires_payment_method') {
            return false;
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
        if (array_key_exists('last_payment_error', $this->data) && !empty($this->data['last_payment_error']) && (!array_key_exists('next_action', $this->data) || empty($this->data['next_action']))) {
            return false;
        }

        if (array_key_exists('status', $this->data) && $this->data['status'] === 'requires_payment_method') {
            return true;
        }

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
        if (array_key_exists('status', $this->data) && $this->data['status'] === 'requires_payment_method') {
            return [
                'client_secret' => $this->data['client_secret'],
                'payment_intent' => $this->data['id'],
            ];
        }

        return [];
    }

    /**
     * @inheritdoc
     */
    public function getRedirectUrl(): string
    {
        // If we have a payment intent that was created without a payment source / payment method, then we
        // make the return URL the same page as the request as they only need the redirect data with contains the client secret.
        if (array_key_exists('status', $this->data) && $this->data['status'] === 'requires_payment_method') {
            return \Craft::$app->getRequest()->getUrl();
        }

        // Regular next action URL which might be a 3DS page etc.
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
    public function getData(): mixed
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
    public function redirect(): void
    {
        throw new NotImplementedException('Redirecting directly is not implemented for this gateway.');
    }
}
