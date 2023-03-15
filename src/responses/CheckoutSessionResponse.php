<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\responses;

use craft\commerce\base\RequestResponseInterface;
use yii\base\Exception;

class CheckoutSessionResponse implements RequestResponseInterface
{
    /**
     * @var array the response data
     */
    protected array $data = [];

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
        return false; // always redirect
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
        return true;
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
        if (!isset($this->data['url'])) {
            throw new Exception('No url found in checkout session to redirect to');
        }

        return (string)$this->data['url'];
    }

    /**
     * @inheritdoc
     */
    public function getTransactionReference(): string
    {
        if (!isset($this->data['id'])) {
            throw new Exception('No checkout session ID found');
        }

        return (string)$this->data['id'];
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return '';
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
        return '';
    }

    /**
     * @inheritdoc
     */
    public function redirect(): void
    {
    }
}
