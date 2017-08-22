<?php

namespace craft\commerce\stripe\responses;

use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\NotImplementedException;

class Response implements RequestResponseInterface
{
    /**
     * @var
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
    public function isRedirect(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getRedirectMethod()
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function getRedirectData()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getRedirectUrl()
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function getTransactionReference()
    {
        if (empty($this->data)) {
            return '';
        }

        return $this->data['id'];
    }

    /**
     * @inheritdoc
     */
    public function getCode()
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
    public function getMessage()
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
        throw new NotImplementedException('Redirect is not implemented for this gateway.');
    }

}