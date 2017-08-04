<?php

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\omnipay\base\CreditCardGateway;
use craft\commerce\stripe\models\StripePaymentForm;
use Omnipay\Common\AbstractGateway;
use Omnipay\Omnipay;
use Omnipay\Stripe\Gateway;

/**
 * Stripe represents the Stripe gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class Stripe extends CreditCardGateway
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public $publishableKey;

    /**
     * @var string
     */
    public $apiKey;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Stripe');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('commerce-stripe/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params)
    {
        $defaults = [
            'gateway' => $this,
            'paymentForm' => $this->getPaymentFormModel()
        ];

        $params = array_merge($defaults, $params);

        Craft::$app->getView()->registerJsFile('https://js.stripe.com/v2/');

        return Craft::$app->getView()->renderTemplate('commerce-stripe/paymentForm', $params);
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel()
    {
        return new StripePaymentForm();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var AbstractGateway $gateway */
        $gateway = Omnipay::create($this->getGatewayClassName());

        $gateway->setParameter('apiKey', $this->apiKey);

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName()
    {
        return '\\'.Gateway::class;
    }
}
