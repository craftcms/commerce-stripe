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
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\errors\CustomerException;
use craft\commerce\stripe\errors\PaymentSourceException as CommercePaymentSourceException;
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\events\ReceiveWebhookEvent;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\ChargeResponse;
use craft\helpers\Json;
use craft\helpers\StringHelper;
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
 * This class represents the abstract Stripe base gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
abstract class Gateway extends BaseGateway
{
    // Constants
    // =========================================================================

    /**
     * @event BuildGatewayRequestEvent The event that is triggered when a gateway request is being built.
     *
     * Plugins get a chance to provide additional metadata to any request that is made to Stripe in the context of paying for an order. This includes capturing and refunding transactions.
     *
     * Note, that any changes to the `Transaction` model will be ignored and it is not possible to set `order_number`, `order_id`, `transaction_id`, `transaction_reference`, and `client_ip` metadata keys.
     *
     * ```php
     * use craft\commerce\models\Transaction;
     * use craft\commerce\stripe\events\BuildGatewayRequestEvent;
     * use craft\commerce\stripe\gateways\Gateway as StripeGateway;
     * use yii\base\Event;
     *
     * Event::on(StripeGateway::class, StripeGateway::EVENT_BUILD_GATEWAY_REQUEST, function(BuildGatewayRequestEvent $e) {
     *     if ($e->transaction->type === 'refund') {
     *         $e->metadata['someKey'] = 'some value';
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
     * use craft\commerce\stripe\gateways\Gateway as StripeGateway;
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
     * @event Receive3dsPaymentEvent The event that is triggered when a successful 3ds payment is received.
     *
     * Plugins get a chance to do something whenever a successful 3D Secure payment is received.
     *
     * ```php
     * use craft\commerce\Plugin as Commerce;
     * use craft\commerce\stripe\events\Receive3dsPaymentEvent;
     * use craft\commerce\stripe\gateways\Gateway as StripeGateway;
     * use yii\base\Event;
     *
     * Event::on(StripeGateway::class, StripeGateway::EVENT_RECEIVE_3DS_PAYMENT, function(Receive3dsPaymentEvent $e) {
     *     $order = $e->transaction->getOrder();
     *     $orderStatus = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle('paid');
     *     if ($order && $paidStatus && $order->orderStatusId !== $paidStatus->id && $order->getIsPaid()) {
     *         $order->orderStatusId = $paidStatus->id;
     *         Craft::$app->getElements()->saveElement($order);
     *     }
     * });
     * ```
     */
    const EVENT_RECEIVE_3DS_PAYMENT = 'receive3dsPayment';

    /**
     * string The Stripe API version to use.
     */
    const STRIPE_API_VERSION = '2019-03-14';

    // Public Methods
    // =========================================================================

    public function init()
    {
        parent::init();

        Stripe::setAppInfo(StripePlugin::getInstance()->name, StripePlugin::getInstance()->version, StripePlugin::getInstance()->documentationUrl);
        Stripe::setApiKey(Craft::parseEnv($this->apiKey));
        Stripe::setApiVersion(self::STRIPE_API_VERSION);

        $this->signingSecret = Craft::parseEnv($this->signingSecret);
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
        // It's exactly the same thing,
        return $this->completePurchase($transaction);
    }

    /**
     * @inheritdoc
     */
    public function createPaymentSource(BasePaymentForm $sourceData, int $userId): PaymentSource
    {
        /** @var Payment $sourceData */
        $sourceData->token = $this->normalizePaymentToken((string)$sourceData->token);

        try {
            $stripeCustomer = $this->getStripeCustomer($userId);
            $stripeResponse = $stripeCustomer->sources->create(['source' => $sourceData->token]);

            $stripeCustomer->default_source = $stripeResponse->id;
            $stripeCustomer->save();

            switch ($stripeResponse->type) {
                case 'card':
                    $description = Craft::t('commerce-stripe', '{cardType} ending in ••••{last4}', ['cardType' => $stripeResponse->card->brand, 'last4' => $stripeResponse->card->last4]);
                    break;
                default:
                    $description = $stripeResponse->type;
            }

            $paymentSource = new PaymentSource([
                'userId' => $userId,
                'gatewayId' => $this->id,
                'token' => $stripeResponse->id,
                'response' => $stripeResponse->jsonSerialize(),
                'description' => $description
            ]);

            return $paymentSource;
        } catch (\Throwable $exception) {
            throw new CommercePaymentSourceException($exception->getMessage());
        }
    }

    /**
     * @inheritdoc
     */
    public function deletePaymentSource($token): bool
    {
        try {
            /** @var Source $source */
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
    public function processWebHook(): WebResponse
    {
        $rawData = Craft::$app->getRequest()->getRawBody();
        $response = Craft::$app->getResponse();

        $secret = $this->signingSecret;
        $stripeSignature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (!$secret || !$stripeSignature) {
            Craft::warning('Webhook not signed or signing secret not set.', 'stripe');
            $response->data = 'ok';

            return $response;
        }

        try {
            // Check the payload and signature
            Webhook::constructEvent($rawData, $stripeSignature, $secret);
        } catch (\Exception $exception) {
            Craft::warning('Webhook signature check failed: ' . $exception->getMessage(), 'stripe');
            $response->data = 'ok';

            return $response;
        }

        $data = Json::decodeIfJson($rawData);

        if ($data) {
            $this->handleWebhook($data);

            if ($this->hasEventHandlers(self::EVENT_RECEIVE_WEBHOOK)) {
                $this->trigger(self::EVENT_RECEIVE_WEBHOOK, new ReceiveWebhookEvent([
                    'webhookData' => $data
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
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($transaction->paymentCurrency);

        if (!$currency) {
            throw new NotSupportedException('The currency “' . $transaction->paymentCurrency . '” is not supported!');
        }

        try {
            $request = [
                'charge' => $transaction->reference,
                'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
            ];
            $refund = Refund::create($request);

            return $this->createPaymentResponseFromApiResource($refund);
        } catch (\Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
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
     * Returns response model wrapping the passed data.
     *
     * @param mixed $data
     *
     * @return RequestResponseInterface
     */
    abstract public function getResponseModel($data): RequestResponseInterface;

    // Protected methods
    // =========================================================================

    /**
     * Build the request data array.
     *
     * @param Transaction $transaction the transaction to be used as base
     *
     * @return array
     * @throws NotSupportedException
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
            'metadata' => $metadata
        ];

        $event = new BuildGatewayRequestEvent([
            'transaction' => $transaction,
            'metadata' => $metadata,
            'request' => $request
        ]);

        // TODO provide context
        $this->trigger(self::EVENT_BUILD_GATEWAY_REQUEST, $event);

        $request = array_merge($event->request, $request);
        $request['metadata'] = array_merge($event->metadata, $metadata);

        if ($this->sendReceiptEmail) {
            $request['receipt_email'] = $transaction->getOrder()->email;
        }

        return $request;
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
        $data = $resource->jsonSerialize();

        return $this->getResponseModel($data);
    }

    /**
     * Create a Response object from an Exception.
     *
     * @param \Exception $exception
     *
     * @return RequestResponseInterface
     * @throws \Exception if not a Stripe exception
     */
    protected function createPaymentResponseFromError(\Exception $exception): RequestResponseInterface
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
            return Customer::retrieve($customer->reference);
        } catch (\Exception $exception) {
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
        if (StringHelper::substr($token, 0, 4) === 'tok_') {
            try {
                /** @var Source $tokenSource */
                $tokenSource = Source::create([
                    'type' => 'card',
                    'token' => $token
                ]);

                return $tokenSource->id;
            } catch (\Exception $exception) {
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
     * @throws \Exception if reasons
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
        // Do nothing
    }
}
