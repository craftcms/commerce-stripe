<?php

namespace craft\commerce\stripe\models;

use craft\commerce\models\payments\CreditCardPaymentForm;

/**
 * Stripe Payment form model.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class StripePaymentForm extends CreditCardPaymentForm
{
    /**
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true)
    {
        parent::setAttributes($values, $safeOnly);

        if (isset($values['stripeToken'])) {
            $this->token = $values['stripeToken'];
        }
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        if (empty($this->token)) {
            return parent::rules();
        }

        return [];
    }
}