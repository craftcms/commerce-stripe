<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\base;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\base\SubscriptionGateway as BaseGateway;
use craft\commerce\errors\PaymentException;
use craft\commerce\errors\TransactionException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\errors\CustomerException;
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\events\BuildSetupIntentRequestEvent;
use craft\commerce\stripe\events\ReceiveWebhookEvent;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\log\MonologTarget;
use craft\web\Response;
use craft\web\Response as WebResponse;
use Exception;
use Stripe\ApiResource;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;
use yii\base\NotSupportedException;

/**
 * This class represents the abstract Stripe base gateway
 *
 * @property bool $sendReceiptEmail
 * @property string $apiKey
 * @property string $publishableKey
 * @property string $signingSecret
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
abstract class Gateway extends BaseGateway
{
    /**
     * @event BuildGatewayRequestEvent The event that is triggered when a gateway request is being built.
     *
     * Plugins get a chance to provide additional data to any request that is made to Stripe in the context of paying for an order. This includes capturing and refunding transactions.
     *
     * There are some restrictions:
     *     Changes to the `Transaction` model available as the `transaction` property will be ignored;
     *     Changes to the `order_id`, `order_number`, `transaction_id`, `client_ip`, and `transaction_reference` metadata keys will be ignored;
     *     Changes to the `amount`, `currency` and `description` request keys will be ignored;
     *
     * ```php
     * use craft\commerce\models\Transaction;
     * use craft\commerce\stripe\events\BuildGatewayRequestEvent;
     * use craft\commerce\stripe\base\Gateway as StripeGateway;
     * use yii\base\Event;
     *
     * Event::on(StripeGateway::class, StripeGateway::EVENT_BUILD_GATEWAY_REQUEST, function(BuildGatewayRequestEvent $e) {
     *     if ($e->transaction->type === 'refund') {
     *         $e->request['someKey'] = 'some value';
     *     }
     * });
     * ```
     *
     */
    public const EVENT_BUILD_GATEWAY_REQUEST = 'buildGatewayRequest';

    /**
     * @event BuildPaymentIntentRequestEvent The event that is triggered when a gateway payment intent request is being built.
     *
     * Plugins get a chance to provide additional data to any request that is made to Stripe in the context of creating a new payment intent for an order.
     *
     * There are some restrictions:
     *     Changes to the `Transaction` model available as the `transaction` property will be ignored;
     *     Changes to the `order_id`, `order_number`, `transaction_id`, `client_ip`, and `transaction_reference` metadata keys will be ignored;
     *     Changes to the `amount`, `currency` request keys will be ignored;
     *
     * ```php
     * use craft\commerce\models\Transaction;
     * use craft\commerce\stripe\events\BuildGatewayRequestEvent;
     * use craft\commerce\stripe\base\Gateway as StripeGateway;
     * use yii\base\Event;
     *
     * Event::on(StripeGateway::class, StripeGateway::EVENT_BUILD_GATEWAY_REQUEST, function(BuildGatewayRequestEvent $e) {
     *     if ($e->transaction->type === 'refund') {
     *         $e->request['someKey'] = 'some value';
     *     }
     * });
     * ```
     *
     */
    public const EVENT_BUILD_PAYMENT_INTENT_REQUEST = 'buildPaymentIntentRequest';

    /**
     * @event ReceiveWebhookEvent The event that is triggered when a valid webhook is received.
     *
     * Plugins get a chance to do something whenever a webhook is received. This event will be fired regardless the Gateway has done something with the webhook or not.
     *
     * ```php
     * use craft\commerce\stripe\events\ReceiveWebhookEvent;
     * use craft\commerce\stripe\base\Gateway as StripeGateway;
     * use yii\base\Event;
     *
     * Event::on(StripeGateway::class, StripeGateway::EVENT_RECEIVE_WEBHOOK, function(ReceiveWebhookEvent $e) {
     *     if ($e->webhookData['type'] == 'charge.dispute.created') {
     *         if ($e->webhookData['data']['object']['amount'] > 1000000) {
     *             // Be concerned that USD 10,000 charge is being disputed.
     *         }
     *     }
     * });
     * ```
     */
    public const EVENT_RECEIVE_WEBHOOK = 'receiveWebhook';

    /**
     * @event BuildSetupIntentRequestEvent The event that is triggered when a SetupIntent is being built
     */
    public const EVENT_BUILD_SETUP_INTENT_REQUEST = 'buildSetupIntentRequest';

    /**
     * string The Stripe API version to use.
     */
    public const STRIPE_API_VERSION = '2022-11-15';

    /**
     * @var string|null
     */
    private ?string $_publishableKey = null;

    /**
     * @var string|null
     */
    private ?string $_apiKey = null;

    /**
     * @var bool|string
     */
    private bool|string $_sendReceiptEmail = false;

    /**
     * @var string|null
     */
    private ?string $_signingSecret = null;

    /**
     * @var StripeClient|null
     */
    private ?StripeClient $_stripeClient = null;

    public function init(): void
    {
        parent::init();
    }

    /**
     * @return StripeClient
     */
    public function getStripeClient(): StripeClient
    {
        if ($this->_stripeClient == null) {
            /** @var MonologTarget $webLogTarget */
            $webLogTarget = Craft::$app->getLog()->targets['web'];

            Stripe::setAppInfo(StripePlugin::getInstance()->name, StripePlugin::getInstance()->version, StripePlugin::getInstance()->documentationUrl);
            Stripe::setApiKey($this->getApiKey());
            Stripe::setApiVersion(self::STRIPE_API_VERSION);
            Stripe::setMaxNetworkRetries(3);
            Stripe::setLogger($webLogTarget->getLogger());

            $this->_stripeClient = new StripeClient([
                'api_key' => $this->getApiKey(),
            ]);
        }

        return $this->_stripeClient;
    }

    /**
     * @inheritdoc
     */
    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $settings['sendReceiptEmail'] = $this->getSendReceiptEmail(false);
        $settings['apiKey'] = $this->getApiKey(false);
        $settings['publishableKey'] = $this->getPublishableKey(false);
        $settings['signingSecret'] = $this->getSigningSecret(false);

        return $settings;
    }

    /**
     * @param string|null $apiKey
     * @return void
     * @since 3.0.0
     */
    public function setApiKey(?string $apiKey): void
    {
        $this->_apiKey = $apiKey;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 3.0.0
     */
    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_apiKey) : $this->_apiKey;
    }

    /**
     * @param string|null $signingSecret
     * @return void
     * @since 3.0.0
     */
    public function setSigningSecret(?string $signingSecret): void
    {
        $this->_signingSecret = $signingSecret;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 3.0.0
     */
    public function getSigningSecret(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_signingSecret) : $this->_signingSecret;
    }

    /**
     * @param string|null $publishableKey
     * @return void
     * @since 3.0.0
     */
    public function setPublishableKey(?string $publishableKey): void
    {
        $this->_publishableKey = $publishableKey;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 3.0.0
     */
    public function getPublishableKey(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_publishableKey) : $this->_publishableKey;
    }

    /**
     * @param bool|string $sendReceiptEmail
     * @return void
     * @since 3.0.0
     */
    public function setSendReceiptEmail(bool|string $sendReceiptEmail): void
    {
        $this->_sendReceiptEmail = $sendReceiptEmail;
    }

    /**
     * @param bool $parse
     * @return bool|string
     * @since 3.0.0
     */
    public function getSendReceiptEmail(bool $parse = true): bool|string
    {
        return $parse ? App::parseBooleanEnv($this->_sendReceiptEmail) : $this->_sendReceiptEmail;
    }

    /**
     * @inheritdoc
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->authorizeOrPurchase($transaction, $form, false);
    }

    /**
     * @inheritdoc
     */
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        return $this->completePurchase($transaction);
    }

    /**
     * @inheritdoc
     */
    public function processWebHook(): WebResponse
    {
        $rawData = Craft::$app->getRequest()->getRawBody();
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;

        $secret = $this->getSigningSecret();
        $stripeSignature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (!$secret || !$stripeSignature) {
            Craft::warning('Webhook not signed or signing secret not set.', 'stripe');
            $response->data = 'ok';

            return $response;
        }

        try {
            // Check the payload and signature
            Webhook::constructEvent($rawData, $stripeSignature, $secret);
        } catch (Exception $exception) {
            Craft::warning('Webhook signature check failed: ' . $exception->getMessage(), 'stripe');
            $response->data = 'ok';

            return $response;
        }

        $data = Json::decodeIfJson($rawData);

        if ($data) {
            try {
                $this->handleWebhook($data);
            } catch (Throwable $exception) {
                Craft::$app->getErrorHandler()->logException($exception);
            }

            if ($this->hasEventHandlers(self::EVENT_RECEIVE_WEBHOOK)) {
                $this->trigger(self::EVENT_RECEIVE_WEBHOOK, new ReceiveWebhookEvent([
                    'webhookData' => $data,
                ]));
            }
        } else {
            Craft::warning('Could not decode JSON payload.', 'stripe');
        }

        $response->data = 'ok';

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->authorizeOrPurchase($transaction, $form);
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
    public function supportsPlanSwitch(): bool
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
    public function supportsReactivation(): bool
    {
        return false;
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
    public function supportsPartialRefund(): bool
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

    /**
     * @inheritDoc
     */
    public function getTransactionHashFromWebhook(): ?string
    {
        $rawData = Craft::$app->getRequest()->getRawBody();
        if (!$rawData) {
            return null;
        }

        $data = Json::decodeIfJson($rawData);
        if (!$data) {
            return null;
        }

        $transactionHash = ArrayHelper::getValue($data, 'data.object.metadata.transaction_reference');
        if (!$transactionHash || !is_string($transactionHash)) {
            $transactionHash = null;
        }

        if (!$transactionHash) {
            // Use the object ID as the unique ID of the stripe object for the transaction hash so we can enforce a mutex
            // in \craft\commerce\services\Webhooks::processWebhook() which call this method.
            $transactionHash = ArrayHelper::getValue($data, 'data.object.id');
        }

        return $transactionHash;
    }

    /**
     * Returns response model wrapping the passed data.
     *
     * @param mixed $data
     *
     * @return RequestResponseInterface
     */
    abstract public function getResponseModel(mixed $data): RequestResponseInterface;

    /**
     * Build the request data array.
     *
     * @param Transaction $transaction the transaction to be used as base
     *
     * @return array
     * @throws NotSupportedException
     * @deprecated 4.0.0 No longer used.
     *
     */
    protected function buildRequestData(Transaction $transaction): array
    {
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($transaction->paymentCurrency);

        if (!$currency) {
            throw new NotSupportedException('The currency “' . $transaction->paymentCurrency . '” is not supported!');
        }

        $metadata = [
            'order_id' => $transaction->getOrder()->id,
            'order_number' => $transaction->getOrder()->number,
            'transaction_id' => $transaction->id,
            'transaction_reference' => $transaction->hash,
        ];

        $appRequest = Craft::$app->getRequest();
        if (!$appRequest->getIsConsoleRequest()) {
            $metadata['client_ip'] = $appRequest->getUserIP();
        }

        $request = [
            'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
            'currency' => $transaction->paymentCurrency,
            'description' => Craft::t('commerce-stripe', 'Order') . ' #' . $transaction->orderId,
            'metadata' => $metadata,
        ];

        $event = new BuildGatewayRequestEvent([
            'transaction' => $transaction,
            'metadata' => $metadata,
            'request' => $request,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BUILD_GATEWAY_REQUEST)) {
            $this->trigger(self::EVENT_BUILD_GATEWAY_REQUEST, $event);

            // Do not allow these to be modified by event handlers
            $event->request['amount'] = $request['amount'];
            $event->request['currency'] = $request['currency'];
        }

        if ($this->sendReceiptEmail) {
            $event->request['receipt_email'] = $transaction->getOrder()->email;
        }

        Craft::$app->getDeprecator()->log(__METHOD__, 'The `\craft\commerce\stripe\base\Gateway::buildRequestData` METHOD has been deprecated. Use a hashed `createPaymentIntent()` or `createSetupIntent()` instead.');

        return $event->request;
    }

    /**
     * Create a Response object from an ApiResponse object.
     *
     * @param ApiResource $resource
     *
     * @return RequestResponseInterface
     */
    protected function createPaymentResponseFromApiResource(ApiResource $resource): RequestResponseInterface
    {
        $data = $resource->toArray();

        return $this->getResponseModel($data);
    }

    /**
     * Create a Response object from an Exception.
     *
     * @param Exception $exception
     *
     * @return RequestResponseInterface
     * @throws Exception if not a Stripe exception
     */
    protected function createPaymentResponseFromError(Exception $exception): RequestResponseInterface
    {
        if ($exception instanceof CardException) {
            $body = $exception->getJsonBody();
            $data = $body;
            $data['code'] = $body['error']['code'];
            $data['message'] = $body['error']['message'];
            if (isset($body['error']['charge'])) {
                $data['id'] = $body['error']['charge'];
            }
        } elseif ($exception instanceof ApiErrorException) {
            // So it's not a card being declined but something else. ¯\_(ツ)_/¯
            $body = $exception->getJsonBody();
            $data = $body;
            $data['id'] = null;
            $data['message'] = $body['error']['message'] ?? $exception->getMessage();
            $data['code'] = $body['error']['code'] ?? $body['error']['type'] ?? $exception->getStripeCode();
        } else {
            throw $exception;
        }

        return $this->getResponseModel($data);
    }

    /**
     * Get the Stripe customer for a User.
     *
     * @param int $userId
     *
     * @return Customer
     * @throws CustomerException if wasn't able to create or retrieve Stripe Customer.
     */
    protected function getStripeCustomer(int $userId): Customer
    {
        try {
            $user = Craft::$app->getUsers()->getUserById($userId);
            $customers = StripePlugin::getInstance()->getCustomers();
            $customer = $customers->getCustomer($this->id, $user);
            $stripeCustomer = $this->getStripeClient()->customers->retrieve($customer->reference);

            if (!empty($stripeCustomer->deleted)) {
                // Okay, retry one time.
                $customers->deleteCustomerById($customer->id);
                $customer = $customers->getCustomer($this->id, $user);
                $stripeCustomer = $this->getStripeClient()->customers->retrieve($customer->reference);
            }

            return $stripeCustomer;
        } catch (Exception $exception) {
            throw new CustomerException('Could not fetch Stripe customer: ' . $exception->getMessage());
        }
    }

    /**
     * Make an authorize or purchase request to Stripe
     *
     * @param Transaction $transaction the transaction on which this request is based
     * @param BasePaymentForm $form payment form parameters
     * @param bool $capture whether funds should be captured immediately, defaults to true.
     *
     * @return RequestResponseInterface
     * @throws NotSupportedException if unrecognized currency specified for transaction
     * @throws PaymentException if unexpected payment information provided.
     * @throws Exception if reasons
     */
    abstract protected function authorizeOrPurchase(Transaction $transaction, BasePaymentForm $form, bool $capture = true): RequestResponseInterface;

    /**
     * Handle a webhook.
     *
     * @param array $data
     * @throws TransactionException
     */
    protected function handleWebhook(array $data): void
    {
        // Nothing
    }

    /**
     * Sets the default payment source in Stripe for the payment source’s customer
     *
     * @param string $customer The Stripe Customer ID
     * @param string $paymentMethodId The Stripe payment method ID
     * @return bool
     * @throws \yii\base\InvalidConfigException
     */
    public function setPaymentSourceAsDefault($customer, $paymentMethodId): bool
    {
        try {
            $this->getStripeClient()->customers->update($customer, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);

            return true;
        } catch (Exception $exception) {
            Craft::error('Unable to set Stripe default payment source: ' . $exception->getMessage());
        }

        return false;
    }

    public function createSetupIntent($params): SetupIntent
    {
        $defaults = [
            'usage' => 'off_session',
        ];

        $params = array_merge($defaults, $params);

        $event = new BuildSetupIntentRequestEvent([
            'request' => $params,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BUILD_SETUP_INTENT_REQUEST)) {
            $this->trigger(self::EVENT_BUILD_SETUP_INTENT_REQUEST, $event);
        }

        return $this->getStripeClient()->setupIntents->create($event->request);
    }
}
