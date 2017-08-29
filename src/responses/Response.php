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
     * @var string
     */
    private $_redirect = '';

    /**
     * @var bool
     */
    private $_processing = false;

    /**
     * Response constructor.
     *
     * @param $data
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    public function setRedirectUrl(string $url)
    {
        $this->_redirect = $url;
    }

    public function setProcessing(bool $status)
    {
        $this->_processing = $status;
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
        return $this->_processing;
    }

    /**
     * @inheritdoc
     */
    public function isRedirect(): bool
    {
        return !empty($this->_redirect);
    }

    /**
     * @inheritdoc
     */
    public function getRedirectMethod()
    {
        return 'GET';
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
        return $this->_redirect;
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
        throw new NotImplementedException('Redirecting directly is not implemented for this gateway.');
    }

}