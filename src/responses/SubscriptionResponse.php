<?php

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

        return (int)$this->data['plan']['trial_period_days'];
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
        return (bool)$this->data['status'] == 'canceled';
    }

    /**
     * @inheritdoc
     */
    public function isScheduledForCancelation(): bool
    {
        return (bool)$this->data['cancel_at_period_end'];
    }


}