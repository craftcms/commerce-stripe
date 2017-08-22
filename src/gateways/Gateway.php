<?php

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\stripe\models\StripePaymentForm;
use craft\commerce\stripe\responses\Response;
use ReflectionProperty;
use Stripe\ApiResource;
use Stripe\Charge;
use Stripe\Error\Card as CardError;
use Stripe\Refund;
use Stripe\Stripe;
use yii\base\NotSupportedException;

/**
 * Stripe represents the Stripe gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class Gateway extends BaseGateway
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

    /**
     * @var bool
     */
    public $sendReceiptEmail;

    // Public Methods
    // =========================================================================

    public function init()
    {
        parent::init();
        
        Stripe::setAppInfo('Stripe for Craft Commerce', '1.0', 'https://github.com/craftcms/commerce-stripe');
        Stripe::setApiKey($this->apiKey);
    }
    
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

    /**
     * @inheritdoc
     */
    public function supportsAuthorize(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsCapture(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsCompletePurchase(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsPurchase(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsRefund(): bool
    {
        return true;
    }

    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        /** @var StripePaymentForm $form */
        $requestData = $this->_createPaymentRequest($transaction, $form);
        $requestData['capture'] = false;

        try {
            $charge = Charge::create($requestData);

            return $this->_createResponseFromCharge($charge);
        } catch (\Exception $exception) {
            return $this->_createResponseFromError($exception);
        }
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        try {
            $charge = Charge::retrieve($reference);
            $charge->capture();

            return $this->_createResponseFromCharge($charge);
        } catch (\Exception $exception) {
            return $this->_createResponseFromError($exception);
        }
    }

    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        // TODO 3d secure.
        throw new NotSupportedException('This gateway does not support the completeAuthorize operation');
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        // TODO 3d secure.
        throw new NotSupportedException('This gateway does not support the completePurchase operation');
    }

    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        /** @var StripePaymentForm $form */
        $requestData = $this->_createPaymentRequest($transaction, $form);

        try {
            $charge = Charge::create($requestData);

            return $this->_createResponseFromCharge($charge);
        } catch (\Exception $exception) {
            return $this->_createResponseFromError($exception);
        }
    }

    public function refund(Transaction $transaction, string $reference): RequestResponseInterface
    {
        $refund = Refund::create(['charge' => $reference]);

        $data = $this->_extractPropertiesFromApiResource($refund);

        return new Response($data);
    }

    private function _createPaymentRequest(Transaction $transaction, StripePaymentForm $paymentForm)
    {
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($transaction->paymentCurrency);
        
        if (!$currency) {
            throw new NotSupportedException('The currency “'.$transaction->paymentCurrency.'” is not supported!');
        }

        $request = [
            'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
            'currency' => $transaction->paymentCurrency,
            'description' => Craft::t('commerce', 'Order').' #'.$transaction->orderId,
            'metadata' => [
                'transactionId' => $transaction->id,
                'clientIp' => Craft::$app->getRequest()->userIP,
                'transactionReference' => $transaction->hash,
            ]
        ];

        if ($this->sendReceiptEmail) {
            $request['receipt_email'] = $transaction->getOrder()->email;
        }

        $request['source'] = $this->_createPaymentSource($transaction, $paymentForm);

        return $request;
    }

    private function _createPaymentSource(Transaction $transaction, StripePaymentForm $paymentForm)
    {
        if ($paymentForm->token)
        {
            return $paymentForm->token;
        }

        $order = $transaction->getOrder();

        $source = [];

        // Card data
        $source['object'] = 'card';
        $source['name'] = $paymentForm->firstName.' '.$paymentForm->lastName;
        $source['number'] = $paymentForm->number;
        $source['exp_year'] = $paymentForm->year;
        $source['exp_month'] = $paymentForm->month;
        $source['cvc'] = $paymentForm->cvv;

        // Billing data (optional)
        if ($order->billingAddressId) {
            $billingAddress = $order->billingAddress;

            if ($billingAddress) {
                $source['address_line1'] = $billingAddress->address1;
                $source['address_line2'] = $billingAddress->address2;
                $source['address_city'] = $billingAddress->city;
                $source['address_zip'] = $billingAddress->zipCode;
                
                if ($billingAddress->getCountry()) {
                    $source['address_country'] = $billingAddress->getCountry()->iso;
                }
                
                if ($billingAddress->getState()) {
                    $source['address_state'] = $billingAddress->getState()->abbreviation ?: $billingAddress->getState()->name;
                } else {
                    $source['address_state'] = $billingAddress->getStateText();
                }
            }
        }

        return $source;
    }

    private function _createResponseFromCharge(Charge $charge)
    {
        $data = $this->_extractPropertiesFromApiResource($charge);
        return new Response($data);
    }

    private function _createResponseFromError(\Exception $exception)
    {
        if ($exception instanceof CardError) {
            $body = $exception->getJsonBody();
            $data = $body;
            $data['code'] = $body['error']['code'];
            $data['message'] = $body['error']['message'];
            $data['id'] = $body['error']['charge'];
        } else {
            // So it's not a card being declined. ¯\_(ツ)_/¯
            throw $exception;
        }

        return new Response($data);
    }

    /**
     * Extract data from a Stripe Api Resource.
     *
     * @param ApiResource $resource
     *
     * @return array $data
     */
    private function _extractPropertiesFromApiResource(ApiResource $resource): array
    {
        $reflection = new \ReflectionClass($resource);

        // It's either extract properties per class or parse the docbloc. Let's take the easy way out.
        $docblock = $reflection->getDocComment();
        $pattern = '/.*\$([a-z0-9_]+)$/mi';

        if(!preg_match_all($pattern, $docblock, $matches))
        {
            return [];
        }

        $fields = $matches[1];

        $data = [];

        foreach ($fields as $field) {
            $data[$field] = $resource->{$field};
        }

        return $data;
    }
}
