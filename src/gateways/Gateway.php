<?php

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\errors\PaymentException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\Plugin as Commerce;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\models\Customer as CustomerModel;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\models\PaymentForm;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\Response;
use craft\commerce\stripe\StripePaymentBundle;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use craft\web\Response as WebResponse;
use Stripe\ApiResource;
use Stripe\Charge;
use Stripe\Customer;
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
 */
class Gateway extends BaseGateway
{
    // Constants
    // =========================================================================

    /**
     * @event BuildGatewayRequestEvent The event that is triggered when a gateway request is being built.
     */
    const EVENT_BUILD_GATEWAY_REQUEST = 'buildGatewayRequest';


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
        /** @var PaymentForm $form */
        $requestData = $this->_buildRequestData($transaction);
        $paymentSource = $this->_buildRequestPaymentSource($transaction, $form, $requestData);
        $requestData['capture'] = false;

        if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect')
        {
            // This should only happen for 3D secure payments.
            $response = $this->_createResponseFromApiResource($paymentSource);
            $response->setRedirectUrl($paymentSource->redirect->url);

            return $response;
        }

        $requestData['source'] = $paymentSource;
        $requestData['customer'] = $form->customer;

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
    public function createPaymentSource(BasePaymentForm $sourceData): PaymentSource
    {
        /** @var PaymentForm $sourceData */
        $user = Craft::$app->getUser();

        if ($user->isGuest) {
            $user->loginRequired();
        }

        $customers = StripePlugin::getInstance()->getCustomers();
        $customer = $customers->getCustomer($this->id, $user->getId());

        if (!$customer) {
            $stripeCustomer = Customer::create([
                'description' => Craft::t('commerce-stripe', 'Customer for Craft user with ID {id}', ['id' => $user->getId()]),
                'email' => $user->getIdentity()->email
            ]);

            $customerModel = new CustomerModel([
                'userId' => $user->getId(),
                'gatewayId' => $this->id,
                'customerId' => $stripeCustomer->id,
                'response' => $stripeCustomer->jsonSerialize()
            ]);

            $customers->saveCustomer($customerModel);
        } else {
            $stripeCustomer = Customer::retrieve($customer->customerId);
        }

        $stripeResponse = $stripeCustomer->sources->create(['source' => $sourceData->token]);

        switch ($stripeResponse->type) {
            case 'card':
                $description = Craft::t('commerce-stripe', '{cardType} ending in ••••{last4}', ['cardType' => $stripeResponse->card->brand, 'last4' => $stripeResponse->card->last4]);
                break;
            default:
                $description = $stripeResponse->type;
        }

        $paymentSource = new PaymentSource([
            'userId' => $user->getId(),
            'gatewayId' => $this->id,
            'token' => $stripeResponse->id,
            'response' => $stripeResponse->jsonSerialize(),
            'description' => $description
        ]);

        return $paymentSource;
    }

    /**
     * @inheritdoc
     */
    public function deletePaymentSource($token): bool
    {
        try {
            $source = Source::retrieve($token);
            $source->detach();
        } catch (\Throwable $throwable) {
            // Assume deleted.
        }

        return true;
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
        return new PaymentForm();
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
            if (!empty($data['data']['object']['metadata']['three_d_secure_flow'])) {
                $this->_handle3DSecureFlowEvent($data);
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
        /** @var PaymentForm $form */
        $requestData = $this->_buildRequestData($transaction);
        $paymentSource = $this->_buildRequestPaymentSource($transaction, $form, $requestData);

        if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect')
        {
            // This should only happen for 3D secure payments.
            $response = $this->_createResponseFromApiResource($paymentSource);
            $response->setRedirectUrl($paymentSource->redirect->url);

            return $response;
        }

        $requestData['source'] = $paymentSource;
        $requestData['customer'] = $form->customer;

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
    public function supportsPaymentSources(): bool
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
        ];

        $event = new BuildGatewayRequestEvent([
            'transaction' => $transaction,
            'metadata' => []
        ]);

        $this->trigger(self::EVENT_BUILD_GATEWAY_REQUEST, $event);

        $metadata = [
            'transactionId' => $transaction->id,
            'clientIp' => Craft::$app->getRequest()->userIP,
            'transactionReference' => $transaction->hash,
        ];

        // Allow other plugins to add metadata, but do not allow tampering.
        $request['metadata'] = array_merge($event->metadata, $metadata);

        if ($this->sendReceiptEmail) {
            $request['receipt_email'] = $transaction->getOrder()->email;
        }

        return $request;
    }

    /**
     * Build a payment source for request.
     *
     * Depending on input, it can be an array of data, a string or a Source object.
     *
     * @param Transaction $transaction
     * @param PaymentForm $paymentForm
     * @param array       $request
     *
     * @return Source
     * @throws PaymentException if unexpected payment information encountered
     */
    private function _buildRequestPaymentSource(Transaction $transaction, PaymentForm $paymentForm, array $request)
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

            $request['metadata']['three_d_secure_flow'] = true;

            return Source::create($request);
        }

        if ($paymentForm->token)
        {
            $source = Source::retrieve($paymentForm->token);

            // If this was a stored source and it required 3D secure, let's repeat the process.
            if (!empty($source->card->three_d_secure))
            {
                $paymentForm->threeDSecure = true;

                return $this->_buildRequestPaymentSource($transaction, $paymentForm, $request);
            }

            return $source;
        }

        throw new PaymentException(Craft::t('commerce-stripe', 'Cannot process the payment at this time'));
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

    /**
     * Handle 3D Secure related event.
     *
     * @param array       $data
     *
     * @return void
     * @throws \craft\commerce\errors\TransactionException
     */
    private function _handle3DSecureFlowEvent(array $data) {
        $dataObject = $data['data']['object'];
        $sourceId = $dataObject['id'];
        $counter = 0;
        $limit = 20;

        do {
            // Handle cases when Stripe sends us a webhook so soon that we haven't processed the transactions that triggered the webhook
            sleep(1);
            $transaction = Commerce::getInstance()->getTransactions()->getTransactionByReferenceAndStatus($sourceId, TransactionRecord::STATUS_PROCESSING);
            $counter++;
        } while (!$transaction && $counter < $limit);

        if (!$transaction) {
            Craft::warning('Transaction with the reference “'.$sourceId.'” and status “'.TransactionRecord::STATUS_PROCESSING.'” not found when processing webhook '.$data['id'], 'stripe');

            return;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;
        $childTransaction->reference = $data['id'];

        try {
            switch ($data['type']) {
                case 'source.chargeable':
                    $sourceId = $dataObject['id'];
                    $requestData = $this->_buildRequestData($transaction);
                    $requestData['source'] = $sourceId;
                    $requestData['capture'] = !($childTransaction->type === TransactionRecord::TYPE_AUTHORIZE);

                    try {
                        $charge = Charge::create($requestData, ['idempotency_key' => $childTransaction->hash]);

                        $stripeResponse = $this->_createResponseFromApiResource($charge);
                    } catch (\Exception $exception) {
                        $stripeResponse = $this->_createResponseFromError($exception);
                    }

                    if ($stripeResponse->isSuccessful()) {
                        $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
                    } else {
                        $childTransaction->status = TransactionRecord::STATUS_FAILED;
                    }

                    $childTransaction->response = $stripeResponse->getData();
                    $childTransaction->code = $stripeResponse->getCode();
                    $childTransaction->reference = $stripeResponse->getTransactionReference();
                    $childTransaction->message = $stripeResponse->getMessage();

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
}
