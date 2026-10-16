<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\responses;

use craft\commerce\base\SubscriptionResponseInterface;
use craft\helpers\DateTimeHelper;
use DateTime;
use yii\base\InvalidConfigException;

class SubscriptionResponse implements SubscriptionResponseInterface
{
    /**
     * @var array
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
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * @inheritdoc
     */
    public function getTrialDays(): int
    {
        if (empty($this->data) || empty($this->data['trial_end']) || empty($this->data['trial_start'])) {
            return 0;
        }

        return (int)(($this->data['trial_end'] - $this->data['trial_start']) / 60 / 60 / 24);
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException if no data
     */
    public function getNextPaymentDate(): DateTime
    {
        if (empty($this->data)) {
            throw new InvalidConfigException();
        }

        $itemPeriodEnds = array_column($this->data['items']['data'] ?? [], 'current_period_end');
        $timestamp = !empty($itemPeriodEnds) ? min($itemPeriodEnds) : ($this->data['current_period_end'] ?? null);

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

    /**
     * @inheritdoc
     */
    public function isInactive(): bool
    {
        return $this->data['status'] === 'incomplete' || $this->data['status'] === 'incomplete_expired';
    }
}
