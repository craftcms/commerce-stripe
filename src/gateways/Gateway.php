<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\base\Plan as BasePlan;
use craft\commerce\base\PlanInterface;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\base\SubscriptionGateway as BaseGateway;
use craft\commerce\base\SubscriptionResponseInterface;
use craft\commerce\elements\Subscription;
use craft\commerce\errors\PaymentException;
use craft\commerce\stripe\errors\PaymentSourceException as CommercePaymentSourceException;
use craft\commerce\errors\SubscriptionException;
use craft\commerce\errors\TransactionException;
use craft\commerce\models\Currency;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\subscriptions\CancelSubscriptionForm as BaseCancelSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionPayment;
use craft\commerce\models\subscriptions\SwitchPlansForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\errors\CustomerException;
use craft\commerce\stripe\errors\PaymentSourceException;
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\events\CreateInvoiceEvent;
use craft\commerce\stripe\events\ReceiveWebhookEvent;
use craft\commerce\stripe\models\forms\CancelSubscription;
use craft\commerce\stripe\models\forms\Payment;
use craft\commerce\stripe\models\forms\SwitchPlans;
use craft\commerce\stripe\models\Invoice;
use craft\commerce\stripe\models\Plan;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\PaymentResponse;
use craft\commerce\stripe\responses\SubscriptionResponse;
use craft\commerce\stripe\web\assets\paymentform\PaymentFormAsset;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Response as WebResponse;
use craft\web\View;
use Stripe\ApiResource;
use Stripe\Charge;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\Error\Base;
use Stripe\Error\Card as CardError;
use Stripe\Invoice as StripeInvoice;
use Stripe\Plan as StripePlan;
use Stripe\Product as StripeProduct;
use Stripe\Refund;
use Stripe\Source;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;
use yii\base\NotSupportedException;

/**
 * Stripe represents the Stripe gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Gateway extends BaseGateway
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
     * @event CreateInvoiceEvent The event that is triggered when an invoice is being created on the gateway.
     *
     * Plugins get a chance to do something when an invoice is created on the Stripe gateway.
     *
     * ```php
     * use craft\commerce\stripe\events\CreateInvoiceEvent;
     * use craft\commerce\stripe\gateways\Gateway as StripeGateway;
     * use yii\base\Event;
     *
     * Event::on(StripeGateway::class, StripeGateway::EVENT_CREATE_INVOICE, function(CreateInvoiceEvent $e) {
     *     if ($e->invoiceData['billing'] === 'send_invoice') {
     *         // Forward this invoice to the accounting dpt.
     *     }
     * });
     * ```
     */
    const EVENT_CREATE_INVOICE = 'createInvoice';

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
     * string The Stripe API version to use.
     */
    const STRIPE_API_VERSION = '2018-02-06';

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

        Stripe::setAppInfo(StripePlugin::getInstance()->name, StripePlugin::getInstance()->version, StripePlugin::getInstance()->documentationUrl);
        Stripe::setApiKey($this->apiKey);
        Stripe::setApiVersion(self::STRIPE_API_VERSION);
    }

    /**
     * @inheritdoc
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        /** @var Payment $form */
        $requestData = $this->_buildRequestData($transaction);
        $paymentSource = $this->_buildRequestPaymentSource($transaction, $form, $requestData);
        $requestData['capture'] = false;

        if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect') {
            // This should only happen for 3D secure payments.
            $response = $this->_createPaymentResponseFromApiResource($paymentSource);
            $response->setRedirectUrl($paymentSource->redirect->url);

            return $response;
        }

        $requestData['source'] = $paymentSource;
        $requestData['customer'] = $form->customer;

        try {
            $charge = Charge::create($requestData, ['idempotency_key' => $transaction->hash]);

            return $this->_createPaymentResponseFromApiResource($charge);
        } catch (\Exception $exception) {
            return $this->_createPaymentResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function cancelSubscription(Subscription $subscription, BaseCancelSubscriptionForm $parameters): SubscriptionResponseInterface
    {
        try {
            $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
            /** @var CancelSubscription $parameters */
            $response = $stripeSubscription->cancel(['at_period_end' => !$parameters->cancelImmediately]);

            return $this->_createSubscriptionResponse($response);
        } catch (\Throwable $exception) {
            throw new SubscriptionException('Failed to cancel subscription: '.$exception->getMessage());
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

            return $this->_createPaymentResponseFromApiResource($charge);
        } catch (\Exception $exception) {
            return $this->_createPaymentResponseFromError($exception);
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

        $response = $this->_createPaymentResponseFromApiResource($paymentSource);
        $response->setProcessing(true);

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function createPaymentSource(BasePaymentForm $sourceData, int $userId): PaymentSource
    {
        /** @var Payment $sourceData */
        $sourceData->token = $this->_normalizePaymentToken((string) $sourceData->token);

        try {
            $stripeCustomer = $this->_getStripeCustomer($userId);
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
        return Craft::t('commerce-stripe', 'Stripe');
    }

    /**
     * @inheritdoc
     */
    public function getCancelSubscriptionFormHtml(): string
    {
        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $view->renderTemplate('commerce-stripe/cancelSubscriptionForm');
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    public function getCancelSubscriptionFormModel(): BaseCancelSubscriptionForm
    {
        return new CancelSubscription();
    }

    /**
     * @inheritdoc
     */
    public function getNextPaymentAmount(Subscription $subscription): string
    {
        $data = Json::decode($subscription->subscriptionData);
        $currencyCode = StringHelper::toUpperCase($data['plan']['currency']);
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($currencyCode);

        if (!$currency) {
            Craft::warning('Unsupported currency - '.$currencyCode, 'stripe');

            return (float)0;
        }

        return $data['plan']['amount'] / (10 ** $currency->minorUnit).' '.$currencyCode;
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
        $view->registerAssetBundle(PaymentFormAsset::class);

        $html = $view->renderTemplate('commerce-stripe/paymentForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     * @return Payment
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new Payment();
    }

    /**
     * @inheritdoc
     */
    public function getPlanModel(): BasePlan
    {
        return new Plan();
    }

    /**
     * @inheritdoc
     */
    public function getPlanSettingsHtml(array $params = [])
    {
        return Craft::$app->getView()->renderTemplate('commerce-stripe/planSettings', $params);
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
    public function getSubscriptionFormHtml(): string
    {
        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $view->renderTemplate('commerce-stripe/subscriptionForm');
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionFormModel(): SubscriptionForm
    {
        return new SubscriptionForm();
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPayments(Subscription $subscription): array
    {
        $payments = [];

        $invoices = StripePlugin::getInstance()->getInvoices()->getSubscriptionInvoices($subscription->id);

        foreach ($invoices as $invoice) {
            $data = $invoice->invoiceData;

            $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso(StringHelper::toUpperCase($data['currency']));

            if (!$currency) {
                Craft::warning('Unsupported currency - '.$data['currency'], 'stripe');
                continue;
            }

            $payments[$data['date']] = $this->_createSubscriptionPayment($data, $currency);
        }

        // Sort them by time invoiced, not the time they were saved to DB
        krsort($payments);

        return $payments;
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPlanByReference(string $reference): string
    {
        if (empty($reference)) {
            return '';
        }

        $plan = StripePlan::retrieve($reference);
        $plan = $plan->jsonSerialize();

        $product = StripeProduct::retrieve($plan['product']);
        $product = $product->jsonSerialize();

        return Json::encode(['plan' => $plan, 'product' => $product]);
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPlans(): array
    {
        /** @var Collection $plans */
        $plans = StripePlan::all();
        $output = [];

        $planList = [];
        if (\count($plans->data)) {
            foreach ($plans->data as $plan) {
                $plan = $plan->jsonSerialize();
                $planList[$plan['product']] = $plan['id'];
            }

            /** @var Collection $products */
            $products = StripeProduct::all([
                'limit' => 100,
                'ids' => array_keys($planList)
            ]);

            if (\count($products->data)) {
                foreach ($products->data as $product) {
                    $product = $product->jsonSerialize();
                    $output[] = ['name' => $product['name'], 'reference' => $planList[$product['id']]];
                }
            }
        }

        return $output;
    }

    /**
     * @inheritdoc
     */
    public function getSwitchPlansFormHtml(PlanInterface $originalPlan, PlanInterface $targetPlan): string
    {
        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        /** @var Plan $originalPlan */
        /** @var Plan $targetPlan */
        $html = $view->renderTemplate('commerce-stripe/switchPlansForm', ['plansOnSameCycle' => $originalPlan->isOnSamePaymentCycleAs($targetPlan)]);

        $view->setTemplateMode($previousMode);

        return $html;
    }


    /**
     * @inheritdoc
     */
    public function getSwitchPlansFormModel(): SwitchPlansForm
    {
        return new SwitchPlans();
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
            $response->data = 'ok';

            return $response;
        }

        try {
            // Check the payload and signature
            Webhook::constructEvent($rawData, $stripeSignature, $secret);
        } catch (\Exception $exception) {
            Craft::warning('Webhook signature check failed: '.$exception->getMessage(), 'stripe');
            $response->data = 'ok';

            return $response;
        }

        $data = Json::decodeIfJson($rawData);

        try {
            if ($data) {
                switch ($data['type']) {
                    case 'plan.deleted':
                    case 'plan.updated':
                        $this->_handlePlanEvent($data);
                        break;
                    case 'invoice.payment_succeeded':
                        $this->_handleInvoiceSucceededEvent($data);
                        break;
                    case 'invoice.created':
                        $this->_handleInvoiceCreated($data);
                        break;
                    case 'customer.subscription.deleted':
                        $this->_handleSubscriptionExpired($data);
                        break;
                    case 'customer.subscription.updated':
                        $this->_handleSubscriptionUpdated($data);
                        break;
                    default:
                        if (!empty($data['data']['object']['metadata']['three_d_secure_flow'])) {
                            $this->_handle3DSecureFlowEvent($data);
                        }
                }

                if ($this->hasEventHandlers(self::EVENT_RECEIVE_WEBHOOK)) {
                    $this->trigger(self::EVENT_RECEIVE_WEBHOOK, new ReceiveWebhookEvent([
                        'webhookData' => $data
                    ]));
                }
            } else {
                Craft::warning('Could not decode JSON payload.', 'stripe');
            }
        } catch (\Throwable $exception) {
            Craft::error('Exception while processing webhook: '.$exception->getMessage(), 'stripe');
        }

        $response->data = 'ok';

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        try {
            /** @var Payment $form */
            $requestData = $this->_buildRequestData($transaction);
            $paymentSource = $this->_buildRequestPaymentSource($transaction, $form, $requestData);

            if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect') {
                // This should only happen for 3D secure payments.
                $response = $this->_createPaymentResponseFromApiResource($paymentSource);
                $response->setRedirectUrl($paymentSource->redirect->url);

                return $response;
            }

            $requestData['source'] = $paymentSource;
            $requestData['customer'] = $form->customer;

            $charge = Charge::create($requestData, ['idempotency_key' => $transaction->hash]);

            return $this->_createPaymentResponseFromApiResource($charge);
        } catch (\Exception $exception) {
            return $this->_createPaymentResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function reactivateSubscription(Subscription $subscription): SubscriptionResponseInterface
    {
        /** @var Plan $plan */
        $plan = $subscription->getPlan();

        $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
        $stripeSubscription->items = [
            [
                'id' => $stripeSubscription->items->data[0]->id,
                'plan' => $plan->reference,
            ]
        ];

        return $this->_createSubscriptionResponse($stripeSubscription->save());
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($transaction->paymentCurrency);

        if (!$currency) {
            throw new NotSupportedException('The currency “'.$transaction->paymentCurrency.'” is not supported!');
        }

        try {
            $request = [
                'charge' => $transaction->reference,
                'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
            ];
            $refund = Refund::create($request);

            return $this->_createPaymentResponseFromApiResource($refund);
        } catch (\Exception $exception) {
            return $this->_createPaymentResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     * @throws SubscriptionException if there was a problem subscribing to the plan
     */
    public function subscribe(User $user, BasePlan $plan, SubscriptionForm $parameters): SubscriptionResponseInterface
    {
        try {
            $stripeCustomer = $this->_getStripeCustomer($user->id);
        } catch (CustomerException $exception) {
            Craft::warning($exception->getMessage(), 'stripe');

            throw new SubscriptionException(Craft::t('commerce-stripe', 'Unable to subscribe at this time.'));
        }

        $sources = $stripeCustomer->sources->all();

        if (\count($sources->data) === 0) {
            throw new PaymentSourceException(Craft::t('commerce-stripe', 'No payment sources are saved to use for subscriptions.'));
        }

        try {
            $subscription = StripeSubscription::create([
                'customer' => $stripeCustomer->id,
                'items' => [['plan' => $plan->reference]],
                'trial_period_days' => $parameters->trialDays
            ]);
        } catch (\Throwable $exception) {
            Craft::warning($exception->getMessage(), 'stripe');

            throw new SubscriptionException(Craft::t('commerce-stripe', 'Unable to subscribe at this time.'));
        }

        return $this->_createSubscriptionResponse($subscription);
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
    public function supportsReactivation(): bool
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
     * @inheritdoc
     */
    public function switchSubscriptionPlan(Subscription $subscription, BasePlan $plan, SwitchPlansForm $parameters): SubscriptionResponseInterface
    {
        /** @var SwitchPlans $parameters */
        $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
        $stripeSubscription->items = [
            [
                'id' => $stripeSubscription->items->data[0]->id,
                'plan' => $plan->reference,
            ]
        ];
        $stripeSubscription->prorate = (bool)$parameters->prorate;

        $response = $this->_createSubscriptionResponse($stripeSubscription->save());

        if ($parameters->billImmediately) {
            StripeInvoice::create([
                'customer' => $stripeSubscription->customer,
                'subscription' => $stripeSubscription->id
            ]);
        }

        return $response;
    }

    // Private methods
    // =========================================================================

    /**
     * Build the request data array.
     *
     * @param Transaction $transaction the transaction to be used as base
     *
     * @return array
     * @throws NotSupportedException
     */
    private function _buildRequestData(Transaction $transaction): array
    {
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($transaction->paymentCurrency);

        if (!$currency) {
            throw new NotSupportedException('The currency “'.$transaction->paymentCurrency.'” is not supported!');
        }

        $request = [
            'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
            'currency' => $transaction->paymentCurrency,
            'description' => Craft::t('commerce-stripe', 'Order').' #'.$transaction->orderId,
        ];

        $event = new BuildGatewayRequestEvent([
            'transaction' => $transaction,
            'metadata' => []
        ]);

        $this->trigger(self::EVENT_BUILD_GATEWAY_REQUEST, $event);

        $metadata = [
            'order_id' => $transaction->getOrder()->id,
            'order_number' => $transaction->getOrder()->number,
            'transaction_id' => $transaction->id,
            'transaction_reference' => $transaction->hash,
            'client_ip' => Craft::$app->getRequest()->userIP,
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
     * @param Transaction $transaction the transaction to be used as base
     * @param Payment $paymentForm the payment form
     * @param array $request the request data
     *
     * @return Source
     * @throws PaymentException if unexpected payment information encountered
     */
    private function _buildRequestPaymentSource(Transaction $transaction, Payment $paymentForm, array $request): Source
    {
        // For 3D secure, make sure to set the redirect URL and the metadata flag, so we can catch it later.
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

        if ($paymentForm->token) {
            $paymentForm->token = $this->_normalizePaymentToken((string) $paymentForm->token);
            $source = Source::retrieve($paymentForm->token);

            // If this required 3D secure, let's set the flag for it  and repeat
            if (!empty($source->card->three_d_secure) && $source->card->three_d_secure == 'required') {
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
     * @return PaymentResponse
     */
    private function _createPaymentResponseFromApiResource(ApiResource $resource): PaymentResponse
    {
        $data = $resource->jsonSerialize();

        return new PaymentResponse($data);
    }

    /**
     * Create a Response object from an Exception.
     *
     * @param \Exception $exception
     *
     * @return PaymentResponse
     * @throws \Exception if not a Stripe exception
     */
    private function _createPaymentResponseFromError(\Exception $exception): PaymentResponse
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

        return new PaymentResponse($data);
    }

    /**
     * Create a subscription payment model from invoice.
     *
     * @param array $data
     * @param Currency $currency the currency used for payment
     *
     * @return SubscriptionPayment
     */
    private function _createSubscriptionPayment(array $data, Currency $currency): SubscriptionPayment
    {
        $payment = new SubscriptionPayment([
            'paymentAmount' => $data['amount_due'] / (10 ** $currency->minorUnit),
            'paymentCurrency' => $currency,
            'paymentDate' => $data['date'],
            'paymentReference' => $data['charge'],
            'paid' => $data['paid'],
            'forgiven' => $data['forgiven'],
            'response' => Json::encode($data)
        ]);

        return $payment;
    }

    /**
     * Create a Subscription Response object from an ApiResponse object.
     *
     * @param ApiResource $resource
     *
     * @return SubscriptionResponseInterface
     */
    private function _createSubscriptionResponse(ApiResource $resource): SubscriptionResponseInterface
    {
        $data = $resource->jsonSerialize();

        return new SubscriptionResponse($data);
    }

    /**
     * Handle a 3D Secure related event.
     *
     * @param array $data
     *
     * @return void
     * @throws TransactionException if unable to save transaction
     */
    private function _handle3DSecureFlowEvent(array $data)
    {
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

                        $stripeResponse = $this->_createPaymentResponseFromApiResource($charge);
                    } catch (\Exception $exception) {
                        $stripeResponse = $this->_createPaymentResponseFromError($exception);
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
                    $childTransaction->message = Craft::t('commerce-stripe', 'Failed to process the charge.');
                    $childTransaction->response = Json::encode($data);
                    break;
            }

            Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);
        } catch (\Exception $exception) {
            Craft::error('Could not process webhook '.$data['id'].': '.$exception->getMessage(), 'stripe');
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
            Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);
        }
    }

    /**
     * Handle a created invoice.
     *
     * @param array $data
     */
    private function _handleInvoiceCreated(array $data)
    {
        $stripeInvoice = $data['data']['object'];

        if ($this->hasEventHandlers(self::EVENT_CREATE_INVOICE)) {
            $this->trigger(self::EVENT_CREATE_INVOICE, new CreateInvoiceEvent([
                'invoiceData' => $stripeInvoice
            ]));
        }

        $canBePaid = empty($stripeInvoice['paid']) && $stripeInvoice['billing'] === 'charge_automatically';

        if (StripePlugin::getInstance()->getSettings()->chargeInvoicesImmediately && $canBePaid) {
            $invoice = StripeInvoice::retrieve($stripeInvoice['id']);
            $invoice->pay();
        }
    }

    /**
     * Handle a successful invoice payment event.
     *
     * @param array $data
     *
     * @return void
     * @throws \Throwable if something went wrong when processing the invoice
     */
    private function _handleInvoiceSucceededEvent(array $data)
    {
        $stripeInvoice = $data['data']['object'];

        // Sanity check
        if (!$stripeInvoice['paid']) {
            return;
        }

        $subscriptionReference = $stripeInvoice['subscription'];
        $subscription = Subscription::find()->reference($subscriptionReference)->one();

        if (!$subscription) {
            Craft::warning('Subscription with the reference “'.$subscriptionReference.'” not found when processing webhook '.$data['id'], 'stripe');

            return;
        }

        $invoice = new Invoice();
        $invoice->subscriptionId = $subscription->id;
        $invoice->reference = $stripeInvoice['id'];
        $invoice->invoiceData = $stripeInvoice;
        StripePlugin::getInstance()->getInvoices()->saveInvoice($invoice);

        $lineItems = $stripeInvoice['lines']['data'];

        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso(StringHelper::toUpperCase($invoice->invoiceData['currency']));

        // Find the relevant line item and update subscription end date
        foreach ($lineItems as $lineItem) {
            if ($lineItem['id'] === $subscriptionReference) {
                $payment = $this->_createSubscriptionPayment($invoice->invoiceData, $currency);
                Commerce::getInstance()->getSubscriptions()->receivePayment($subscription, $payment, DateTimeHelper::toDateTime($lineItem['period']['end']));

                return;
            }
        }
    }

    /**
     * Handle Plan events
     *
     * @param array $data
     *
     * @return void
     * @throws \yii\base\InvalidConfigException If plan not
     */
    private function _handlePlanEvent(array $data)
    {
        $planService = Commerce::getInstance()->getPlans();

        if ($data['type'] == 'plan.deleted') {
            $plan = $planService->getPlanByReference($data['data']['object']['id']);

            if ($plan) {
                $planService->archivePlanById($plan->id);
                Craft::warning($plan->name.' was archived because the corresponding plan was deleted on Stripe. (event "'.$data['id'].'")', 'stripe');
            }
        }
    }

    /**
     * Handle an expired subscription.
     *
     * @param array $data
     *
     * @throws \Throwable
     */
    private function _handleSubscriptionExpired(array $data)
    {
        $stripeSubscription = $data['data']['object'];

        $subscription = Subscription::find()->reference($stripeSubscription['id'])->one();

        if (!$subscription) {
            Craft::warning('Subscription with the reference “'.$stripeSubscription['id'].'” not found when processing webhook '.$data['id'], 'stripe');

            return;
        }

        Commerce::getInstance()->getSubscriptions()->expireSubscription($subscription);
    }

    /**
     * Handle an updated subscription.
     *
     * @param array $data
     *
     * @throws \Throwable
     */
    private function _handleSubscriptionUpdated(array $data)
    {
        $stripeSubscription = $data['data']['object'];
        $canceledAt = $data['data']['object']['canceled_at'];

        $subscription = Subscription::find()->reference($stripeSubscription['id'])->one();

        if (!$subscription) {
            Craft::warning('Subscription with the reference “'.$stripeSubscription['id'].'” not found when processing webhook '.$data['id'], 'stripe');

            return;
        }

        // See if we care about this subscription at all
        if ($subscription) {

            $subscription->isCanceled = (bool)$canceledAt;
            $subscription->dateCanceled = $canceledAt ? DateTimeHelper::toDateTime($canceledAt) : null;
            $subscription->nextPaymentDate = DateTimeHelper::toDateTime($data['data']['object']['current_period_end']);

            $planHandle = $data['data']['object']['plan']['id'];
            $plan = Commerce::getInstance()->getPlans()->getPlanByHandle($planHandle);

            if ($plan) {
                $subscription->planId = $plan->id;
            } else {
                Craft::warning($subscription->reference.' was switched to a plan on Stripe that does not exist on this Site. (event "'.$data['id'].'")', 'stripe');
            }

            Commerce::getInstance()->getSubscriptions()->updateSubscription($subscription);
        }
    }

    /**
     * Get the Stripe customer for a User.
     *
     * @param int $userId
     *
     * @return Customer
     * @throws CustomerException if wasn't able to create or retrieve Stripe Customer.
     */
    private function _getStripeCustomer(int $userId): Customer
    {
        try {
            $user = Craft::$app->getUsers()->getUserById($userId);
            $customers = StripePlugin::getInstance()->getCustomers();
            $customer = $customers->getCustomer($this->id, $user);
            return Customer::retrieve($customer->reference);
        } catch (\Exception $exception) {
            throw new CustomerException('Could not fetch Stripe customer: '.$exception->getMessage());
        }
    }

    /**
     * Normalize one-time payment token to a source token, that may or may not be multi-use.
     *
     * @param string $token
     * @return string
     */
    private function _normalizePaymentToken(string $token = ''): string {
        if (StringHelper::substr($token, 0, 4) === 'tok_') {
            try {
                $tokenSource = Source::create([
                    'type' => 'card',
                    'token' => $token
                ]);

                return $tokenSource->id;
            } catch (\Exception $exception) {
                Craft::error('Unable to normalize payment token: '.$token .', because '.$exception->getMessage());
            }
        }

        return $token;
    }
}
