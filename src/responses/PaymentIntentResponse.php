<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\responses;

use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\NotImplementedException;

class PaymentIntentResponse implements RequestResponseInterface
{
    /**
     * @var array the response data
     */
    protected $data = [];

    /**
     * Response constructor.
     *
     * @param $data
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
        return array_key_exists('status', $this->data) && $this->data['status'] === 'succeeded';
    }

    /**
     * @inheritdoc
     */
    public function isProcessing(): bool
    {
        return array_key_exists('status', $this->data) && $this->data['status'] === 'requires_action';
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
        return $this->data['next_action']['redirect_to_url'] ?? '';
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
        if (empty($this->data['message'])) {
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
