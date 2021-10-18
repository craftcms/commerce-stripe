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
use craft\commerce\stripe\events\ReceiveWebhookEvent;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\Response;
use craft\web\Response as WebResponse;
use Exception;
use Stripe\ApiResource;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Source;
use Stripe\Stripe;
use Stripe\Webhook;
use Throwable;
use yii\base\NotSupportedException;

/**
 * This class represents the abstract Stripe base gateway
 *
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
    const EVENT_BUILD_GATEWAY_REQUEST = 'buildGatewayRequest';

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
    const EVENT_RECEIVE_WEBHOOK = 'receiveWebhook';

    /**
     * string The Stripe API version to use.
     */
    const STRIPE_API_VERSION = '2019-03-14';

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
     * @var string
     */
    public $signingSecret;

    public function init()
    {
        parent::init();

        $this->configureStripeClient();
    }

    /**
     * @inheritdoc
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $this->configureStripeClient();
        return $this->authorizeOrPurchase($transaction, $form, false);
    }

    /**
     * @inheritdoc
     */
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        // It's exactly the same thing,
        $this->configureStripeClient();
        return $this->completePurchase($transaction);
    }

    /**
     * @inheritdoc
     */
    public function processWebHook(): WebResponse
    {
        $this->configureStripeClient();
        $rawData = Craft::$app->getRequest()->getRawBody();
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;

        $secret = Craft::parseEnv($this->signingSecret);
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
        $this->configureStripeClient();
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
     * @return string
     * @since 2.3.1
     */
    public function getTransactionHashFromWebhook()
    {
        $this->configureStripeClient();
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
            return null;
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
    abstract public function getResponseModel($data): RequestResponseInterface;

    /**
     * Build the request data array.
     *
     * @param Transaction $transaction the transaction to be used as base
     *
     * @return array
     * @throws NotSupportedException
     */
    protected function buildRequestData(Transaction $transaction, $context = 'charge'): array
    {
        $this->configureStripeClient();
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

            // TODO remove when metadata is removed from the BuildGatewayRequestEvent event
            $event->request['metadata'] = array_replace($event->metadata, $event->request['metadata']);
        }

        if ($this->sendReceiptEmail) {
            $event->request['receipt_email'] = $transaction->getOrder()->email;
        }

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
        $this->configureStripeClient();
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
        $this->configureStripeClient();
        if ($exception instanceof CardException) {
            $body = $exception->getJsonBody();
            $data = $body;
            $data['code'] = $body['error']['code'];
            $data['message'] = $body['error']['message'];
            $data['id'] = $body['error']['charge'];
        } else if ($exception instanceof ApiErrorException) {
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
        $this->configureStripeClient();
        try {
            $user = Craft::$app->getUsers()->getUserById($userId);
            $customers = StripePlugin::getInstance()->getCustomers();
            $customer = $customers->getCustomer($this->id, $user);
            $stripeCustomer = Customer::retrieve($customer->reference);

            if (!empty($stripeCustomer->deleted)) {
                // Okay, retry one time.
                $customers->deleteCustomerById($customer->id);
                $customer = $customers->getCustomer($this->id, $user);
                $stripeCustomer = Customer::retrieve($customer->reference);
            }

            return $stripeCustomer;
        } catch (Exception $exception) {
            throw new CustomerException('Could not fetch Stripe customer: ' . $exception->getMessage());
        }
    }

    /**
     * Normalize one-time payment token to a source token, that may or may not be multi-use.
     *
     * @param string $token
     * @return string
     */
    protected function normalizePaymentToken(string $token = ''): string
    {
        $this->configureStripeClient();
        if (StringHelper::substr($token, 0, 4) === 'tok_') {
            try {
                $tokenSource = Source::create([
                    'type' => 'card',
                    'token' => $token,
                ]);

                return $tokenSource->id;
            } catch (Exception $exception) {
                Craft::error('Unable to normalize payment token: ' . $token . ', because ' . $exception->getMessage());
            }
        }

        return $token;
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
    protected function handleWebhook(array $data)
    {
        $this->configureStripeClient();
        // Do nothing
    }

    /**
     * Sets the stripe global connection to this gateway API key
     */
    public function configureStripeClient()
    {
        Stripe::setAppInfo(StripePlugin::getInstance()->name, StripePlugin::getInstance()->version, StripePlugin::getInstance()->documentationUrl);
        Stripe::setApiKey(Craft::parseEnv($this->apiKey));
        Stripe::setApiVersion(self::STRIPE_API_VERSION);
    }
}
