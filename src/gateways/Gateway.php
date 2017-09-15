<?php

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\models\StripePaymentForm;
use craft\commerce\stripe\responses\Response;
use craft\commerce\stripe\StripePaymentBundle;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use craft\web\Response as WebResponse;
use Stripe\ApiResource;
use Stripe\Charge;
use Stripe\Error\Base;
use Stripe\Error\Card as CardError;
use Stripe\Refund;
use Stripe\Source;
use Stripe\Stripe;
use Stripe\Webhook;
use yii\base\NotSupportedException;

/**
 * Stripe represents the Stripe gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * TODO Events.
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

    /**
     * @var bool
     */
    public $enforce3dSecure;

    /**
     * @var string
     */
    public $signingSecret;
    
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
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        /** @var StripePaymentForm $form */
        $requestData = $this->_buildRequestData($transaction);
        $paymentSource = $this->_createPaymentSource($transaction, $form, $requestData);
        $requestData['capture'] = false;

        if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect')
        {
            // This should only happen for 3D secure payments.
            $response = $this->_createResponseFromApiResource($paymentSource);
            $response->setRedirectUrl($paymentSource->redirect->url);

            return $response;
        }

        $requestData['source'] = $paymentSource;

        try {
            $charge = Charge::create($requestData, ['idempotency_key' => $transaction->hash]);

            return $this->_createResponseFromApiResource($charge);
        } catch (\Exception $exception) {
            return $this->_createResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        try {
            $charge = Charge::retrieve($reference);
            $charge->capture([], ['idempotency_key' => $reference]);

            return $this->_createResponseFromApiResource($charge);
        } catch (\Exception $exception) {
            return $this->_createResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        // It's exactly the same thing,
        return $this->completePurchase($transaction);
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $sourceId = Craft::$app->getRequest()->getParam('source');
        $paymentSource = Source::retrieve($sourceId);

        $response = $this->_createResponseFromApiResource($paymentSource);
        $response->setProcessing(true);

        return $response;
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

        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerJsFile('https://js.stripe.com/v3/');
        $view->registerAssetBundle(StripePaymentBundle::class);

        $html = Craft::$app->getView()->renderTemplate('commerce-stripe/paymentForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
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
    public function processWebHook(): WebResponse
    {
        $rawData = Craft::$app->getRequest()->getRawBody();
        $response = Craft::$app->getResponse();

        $secret = $this->signingSecret;
        $stripeSignature = $_SERVER["HTTP_STRIPE_SIGNATURE"] ?? '';

        if (!$secret || !$stripeSignature) {
            Craft::warning('Webhook not signed or signing secret not set.', 'stripe');

            $response->data =  'ok';
            return $response;
        }

        try {
            // Check the payload and signature
            Webhook::constructEvent($rawData, $stripeSignature, $secret);
        } catch (\Exception $exception) {
            Craft::warning('Webhook signature check failed: '.$exception->getMessage(), 'stripe');

            $response->data =  'ok';
            return $response;
        }

        $data = Json::decodeIfJson($rawData);

        if ($data) {
            $dataObject = $data['data']['object'] ?? null;
            if ($dataObject) {

                $transactionHash = $dataObject['metadata']['transactionReference'] ?? null;
                $transaction = $transactionHash ? Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash) : null;

                if (!$transaction) {
                    Craft::warning('Transaction with the hash “'.$transactionHash.'” not found when processing webhook '.$data['id'], 'stripe');

                    $response->data =  'ok';
                    return $response;
                }
                
                // We're not handling webhooks that are not for processing transactions.
                if ($transaction->status !== TransactionRecord::STATUS_PROCESSING) {
                    $response->data =  'ok';
                    return $response;
                }

                $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
                $childTransaction->type = $transaction->type;
                $childTransaction->reference = $data['id'];

                try {
                    // Todo probably worth moving each event handling out to a separate method and refactor this in general
                    switch ($data['type']) {
                        case 'source.chargeable':
                            $sourceId = $dataObject['id'];
                            $requestData = $this->_buildRequestData($transaction);
                            $requestData['source'] = $sourceId;
                            $requestData['capture'] = !($childTransaction->type === TransactionRecord::TYPE_AUTHORIZE);

                            try {
                                $charge = Charge::create($requestData, ['idempotency_key' => $childTransaction->hash]);

                                $response = $this->_createResponseFromApiResource($charge);
                            } catch (\Exception $exception) {
                                $response = $this->_createResponseFromError($exception);
                            }

                            if ($response->isSuccessful()) {
                                $transaction->status = TransactionRecord::STATUS_SUCCESS;
                            } else {
                                $transaction->status = TransactionRecord::STATUS_FAILED;
                            }

                            $childTransaction->response = $response->getData();
                            $childTransaction->code = $response->getCode();
                            $childTransaction->reference = $response->getTransactionReference();
                            $childTransaction->message = $response->getMessage();

                            break;
                        case 'source.canceled':
                        case 'source.failed':
                            $childTransaction->status = TransactionRecord::STATUS_FAILED;
                            $childTransaction->reference = $data['id'];
                            $childTransaction->code = $data['type'];
                            break;
                    }

                    Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);
                } catch (\Exception $exception) {
                    Craft::error('Could not process webhook '.$data['id'].': '.$exception->getMessage(), 'stripe');
                    $childTransaction->status = TransactionRecord::STATUS_FAILED;
                    Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);
                }
            }

            $response->data =  'ok';
            return $response;
        }
    }

    /**
     * @inheritdoc
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        /** @var StripePaymentForm $form */
        $requestData = $this->_buildRequestData($transaction);
        $paymentSource = $this->_createPaymentSource($transaction, $form, $requestData);

        if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect')
        {
            // This should only happen for 3D secure payments.
            $response = $this->_createResponseFromApiResource($paymentSource);
            $response->setRedirectUrl($paymentSource->redirect->url);

            return $response;
        }

        $requestData['source'] = $paymentSource;

        try {
            $charge = Charge::create($requestData, ['idempotency_key' => $transaction->hash]);

            return $this->_createResponseFromApiResource($charge);
        } catch (\Exception $exception) {
            return $this->_createResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction, string $reference): RequestResponseInterface
    {
        try {
            $refund = Refund::create(['charge' => $reference], ['idempotency_key' => 'refund_'.$reference]);

            return $this->_createResponseFromApiResource($refund);
        } catch (\Exception $exception) {
            return $this->_createResponseFromError($exception);
        }
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
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsCompletePurchase(): bool
    {
        return true;
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

    /**
     * @inheritdoc
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }

    // Private methods
    // =========================================================================

    /**
     * Build the request data array.
     *
     * @param Transaction $transaction
     *
     * @return array
     * @throws NotSupportedException
     */
    private function _buildRequestData(Transaction $transaction)
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

        return $request;
    }

    /**
     * Create a payment source.
     *
     * Depending on input, it can be an array of data, a string or a Source object.
     *
     * @param Transaction       $transaction
     * @param StripePaymentForm $paymentForm
     * @param array             $request
     *
     * @return array|string|Source
     */
    private function _createPaymentSource(Transaction $transaction, StripePaymentForm $paymentForm, array $request)
    {
        if ($paymentForm->threeDSecure) {
            unset($request['description'], $request['receipt_email']);


            $request['type'] = 'three_d_secure';

            $request['three_d_secure'] = [
                'card' => $paymentForm->token
            ];

            $request['redirect'] = [
                'return_url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash])
            ];

            return Source::create($request);
        }

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

    /**
     * Create a Response object from an ApiResponse object.
     *
     * @param ApiResource $resource
     *
     * @return Response
     */
    private function _createResponseFromApiResource(ApiResource $resource): Response
    {
        $data = $resource->jsonSerialize();

        return new Response($data);
    }

    /**
     * Create a Response object from an Exception.
     *
     * @param \Exception $exception
     *
     * @return Response
     * @throws \Exception
     */
    private function _createResponseFromError(\Exception $exception)
    {
        if ($exception instanceof CardError) {
            $body = $exception->getJsonBody();
            $data = $body;
            $data['code'] = $body['error']['code'];
            $data['message'] = $body['error']['message'];
            $data['id'] = $body['error']['charge'];
        } else if ($exception instanceof Base) {
            // So it's not a card being declined but something else. ¯\_(ツ)_/¯
            $body = $exception->getJsonBody();
            $data = $body;
            $data['id'] = null;
            $data['message'] = $body['error']['message'];
            $data['code'] = $body['error']['code'] ?? $body['error']['type'];
        } else {
            throw $exception;
        }

        return new Response($data);
    }
}
