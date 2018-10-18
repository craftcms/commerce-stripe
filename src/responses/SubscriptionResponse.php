<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\responses;


use craft\commerce\base\SubscriptionResponseInterface;
use craft\helpers\DateTimeHelper;
use yii\base\InvalidConfigException;

class SubscriptionResponse implements SubscriptionResponseInterface
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
    public function getReference(): string
    {
        if (empty($this->data)) {
            return '';
        }

        return (string)$this->data['id'];
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
    public function getTrialDays(): int
    {
        if (empty($this->data)) {
            return 0;
        }

        return (int)(($this->data['trial_end'] - $this->data['trial_start']) / 60 / 60 / 24);
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException if no data
     */
    public function getNextPaymentDate(): \DateTime
    {
        if (empty($this->data)) {
            throw new InvalidConfigException();
        }

        $timestamp = $this->data['current_period_end'];

        return DateTimeHelper::toDateTime($timestamp);
    }

    /**
     * @inheritdoc
     */
    public function isCanceled(): bool
    {
        return $this->data['status'] === 'canceled';
    }

    /**
     * @inheritdoc
     */
    public function isScheduledForCancellation(): bool
    {
        return (bool)$this->data['cancel_at_period_end'];
    }


}
