<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\base\Plan as BasePlan;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\base\SubscriptionResponseInterface;
use craft\commerce\elements\Subscription;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\errors\PaymentException;
use craft\commerce\errors\SubscriptionException;
use craft\commerce\errors\TransactionException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\subscriptions\SubscriptionForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\base\SubscriptionGateway as BaseGateway;
use craft\commerce\stripe\errors\CustomerException;
use craft\commerce\stripe\errors\PaymentSourceException;
use craft\commerce\stripe\events\Receive3dsPaymentEvent;
use craft\commerce\stripe\events\SubscriptionRequestEvent;
use craft\commerce\stripe\models\forms\payment\Charge as PaymentForm;
use craft\commerce\stripe\responses\ChargeResponse;
use craft\commerce\stripe\web\assets\chargeform\ChargeFormAsset;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use Stripe\ApiResource;
use Stripe\Charge;
use Stripe\Refund;
use Stripe\Source;
use Stripe\Subscription as StripeSubscription;
use yii\base\NotSupportedException;

/**
 * This class represents the Stripe Charge gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 * @deprecated in 2.0. Use Payment Intents gateway instead.
 */
class Gateway extends BaseGateway
{
    /**
     * @event Receive3dsPaymentEvent The event that is triggered when a successful 3ds payment is received.
     *
     * Plugins get a chance to do something whenever a successful 3D Secure payment is received.
     *
     * ```php
     * use craft\commerce\Plugin as Commerce;
     * use craft\commerce\stripe\events\Receive3dsPaymentEvent;
     * use use craft\commerce\stripe\gateways\Gateway as StripeGateway;
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

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce-stripe', 'Stripe Charge');
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params)
    {
        $this->configureStripeClient();
        $defaults = [
            'gateway' => $this,
            'paymentForm' => $this->getPaymentFormModel(),
        ];

        $params = array_merge($defaults, $params);

        // If there's no order passed, add the current cart if we're not messing around in backend.
        if (!isset($params['order']) && !Craft::$app->getRequest()->getIsCpRequest()) {
            $billingAddress = Commerce::getInstance()->getCarts()->getCart()->getBillingAddress();

            if (!$billingAddress) {
                $billingAddress = Commerce::getInstance()->getCustomers()->getCustomer()->getPrimaryBillingAddress();
            }
        } else {
            $billingAddress = $params['order']->getBillingAddress();
        }

        if ($billingAddress) {
            $params['billingAddress'] = $billingAddress;
        }

        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerJsFile('https://js.stripe.com/v3/');
        $view->registerAssetBundle(ChargeFormAsset::class);

        $html = $view->renderTemplate('commerce-stripe/paymentForms/chargeForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        $this->configureStripeClient();
        return Craft::$app->getView()->renderTemplate('commerce-stripe/gatewaySettings/chargeSettings', ['gateway' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function createPaymentSource(BasePaymentForm $sourceData, int $userId): PaymentSource
    {
        $this->configureStripeClient();
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
                'description' => $description,
            ]);

            return $paymentSource;
        } catch (\Throwable $exception) {
            throw new PaymentSourceException($exception->getMessage());
        }
    }

    /**
     * @inheritdoc
     * @throws SubscriptionException if there was a problem subscribing to the plan
     */
    public function subscribe(User $user, BasePlan $plan, SubscriptionForm $parameters): SubscriptionResponseInterface
    {
        $this->configureStripeClient();
        try {
            $stripeCustomer = $this->getStripeCustomer($user->id);
        } catch (CustomerException $exception) {
            Craft::warning($exception->getMessage(), 'stripe');

            throw new SubscriptionException(Craft::t('commerce-stripe', 'Unable to subscribe at this time.'));
        }

        $sources = $stripeCustomer->sources->all();

        if (\count($sources->data) === 0) {
            throw new PaymentSourceException(Craft::t('commerce-stripe', 'No payment sources are saved to use for subscriptions.'));
        }

        $subscriptionParameters = [
            'customer' => $stripeCustomer->id,
            'items' => [['plan' => $plan->reference]],
        ];

        if ($parameters->trialDays !== null) {
            $subscriptionParameters['trial_period_days'] = (int)$parameters->trialDays;
        } else {
            $subscriptionParameters['trial_from_plan'] = true;
        }

        $event = new SubscriptionRequestEvent([
            'parameters' => $subscriptionParameters,
        ]);

        $this->trigger(self::EVENT_BEFORE_SUBSCRIBE, $event);

        try {
            $subscription = StripeSubscription::create($event->parameters);
        } catch (\Throwable $exception) {
            Craft::warning($exception->getMessage(), 'stripe');

            throw new SubscriptionException(Craft::t('commerce-stripe', 'Unable to subscribe at this time.'));
        }

        return $this->createSubscriptionResponse($subscription);
    }

    /**
     * @inheritdoc
     * @throws NotImplementedException always
     */
    public function getHasBillingIssues(Subscription $subscription): bool
    {
        $this->configureStripeClient();
        throw new NotImplementedException('This gateway does not support that functionality');
    }

    /**
     * @inheritdoc
     */
    public function handleWebhook(array $data)
    {

        $this->configureStripeClient();
        if (!empty($data['data']['object']['metadata']['three_d_secure_flow'])) {
            $this->handle3DSecureFlowEvent($data);
        }

        parent::handleWebhook($data);
    }

    /**
     * Handle a 3D Secure related event.
     *
     * @param array $data
     * @throws TransactionException if reasons
     */
    protected function handle3DSecureFlowEvent(array $data)
    {
        $this->configureStripeClient();

        $dataObject = $data['data']['object'];
        $sourceId = $dataObject['id'];
        $counter = 0;
        $limit = 30;
        $isOrderCompleted = false;

        do {
            // Handle cases when Stripe sends us a webhook so soon that we haven't processed the transactions that triggered the webhook
            sleep(1);
            $transaction = Commerce::getInstance()->getTransactions()->getTransactionByReferenceAndStatus($sourceId, TransactionRecord::STATUS_PROCESSING);

            // TODO implement a more robust solution for waiting for an order to complete
            if ($transaction && $order = $transaction->getOrder()) {
                $isOrderCompleted = $order->isCompleted;
            }
            $counter++;
        } while (!$transaction && !$isOrderCompleted && $counter < $limit);

        if (!$transaction) {
            Craft::error('Transaction with the reference “' . $sourceId . '” and status “' . TransactionRecord::STATUS_PROCESSING . '” not found when processing webhook ' . $data['id'], 'stripe');

            throw new TransactionException('Transaction with the reference “' . $sourceId . '” and status “' . TransactionRecord::STATUS_PROCESSING . '” not found when processing webhook ' . $data['id']);
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->reference = $data['id'];

        try {
            switch ($data['type']) {
                case 'source.chargeable':
                    $sourceId = $dataObject['id'];
                    $requestData = $this->buildRequestData($transaction);
                    $requestData['source'] = $sourceId;
                    $requestData['capture'] = !($childTransaction->type === TransactionRecord::TYPE_AUTHORIZE);

                    try {
                        $charge = Charge::create($requestData, ['idempotency_key' => $childTransaction->hash]);

                        $stripeResponse = $this->createPaymentResponseFromApiResource($charge);
                    } catch (\Exception $exception) {
                        $stripeResponse = $this->createPaymentResponseFromError($exception);
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

            if (
                ($childTransaction->status === TransactionRecord::STATUS_SUCCESS) &&
                $this->hasEventHandlers(self::EVENT_RECEIVE_3DS_PAYMENT)
            ) {
                $this->trigger(self::EVENT_RECEIVE_3DS_PAYMENT, new Receive3dsPaymentEvent([
                    'transaction' => $childTransaction,
                ]));
            }
        } catch (\Exception $exception) {
            Craft::error('Could not process webhook ' . $data['id'] . ': ' . $exception->getMessage(), 'stripe');
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
            Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);
        }
    }

    /**
     * @inheritdoc
     */
    protected function authorizeOrPurchase(Transaction $transaction, BasePaymentForm $form, bool $capture = true): RequestResponseInterface
    {
        $this->configureStripeClient();
        /** @var PaymentForm $form */
        $requestData = $this->buildRequestData($transaction);
        $paymentSource = $this->buildRequestPaymentSource($transaction, $form, $requestData);

        if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect') {
            // This should only happen for 3D secure payments.
            $response = $this->createPaymentResponseFromApiResource($paymentSource);
            $response->setRedirectUrl($paymentSource->redirect->url);

            return $response;
        }

        $requestData['source'] = $paymentSource;

        if ($form->customer) {
            $requestData['customer'] = $form->customer;
        }

        $requestData['capture'] = $capture;

        try {
            $charge = Charge::create($requestData, ['idempotency_key' => $transaction->hash]);

            return $this->createPaymentResponseFromApiResource($charge);
        } catch (\Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        $this->configureStripeClient();
        return new PaymentForm();
    }

    /**
     * @inheritdoc
     */
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        $this->configureStripeClient();
        try {
            /** @var Charge $charge */
            $charge = Charge::retrieve($reference);
            $charge->capture([], ['idempotency_key' => $reference]);

            return $this->createPaymentResponseFromApiResource($charge);
        } catch (\Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $this->configureStripeClient();
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
    public function getResponseModel($data): RequestResponseInterface
    {
        $this->configureStripeClient();
        return new ChargeResponse($data);
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $this->configureStripeClient();
        $sourceId = Craft::$app->getRequest()->getParam('source');
        /** @var Source $paymentSource */
        $paymentSource = Source::retrieve($sourceId);

        $response = $this->createPaymentResponseFromApiResource($paymentSource);
        $response->setProcessing(true);

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function deletePaymentSource($token): bool
    {
        $this->configureStripeClient();
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
    public function hasBillingIssue(Subscription $subscription): bool
    {
        $this->configureStripeClient();
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getBillingIssueDescription(Subscription $subscription): string
    {
        $this->configureStripeClient();
        return '';
    }

    /**
     * @inheritdoc
     */
    public function getBillingIssueResolveFormHtml(Subscription $subscription): string
    {
        $this->configureStripeClient();
        return '';
    }

    /**
     * Build a payment source for request.
     *
     * @param Transaction $transaction the transaction to be used as base
     * @param PaymentForm $paymentForm the payment form
     * @param array $request the request data
     *
     * @return ApiResource
     * @throws PaymentException if unexpected payment information encountered
     */
    protected function buildRequestPaymentSource(Transaction $transaction, PaymentForm $paymentForm, array $request): ApiResource
    {
        $this->configureStripeClient();
        // For 3D secure, make sure to set the redirect URL and the metadata flag, so we can catch it later.
        if ($paymentForm->threeDSecure) {
            unset($request['description'], $request['receipt_email']);


            $request['type'] = 'three_d_secure';

            $request['three_d_secure'] = [
                'card' => $paymentForm->token,
            ];

            $request['redirect'] = [
                'return_url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash]),
            ];

            $request['metadata']['three_d_secure_flow'] = true;

            return Source::create($request);
        }

        if ($paymentForm->token) {
            $paymentForm->token = $this->normalizePaymentToken((string)$paymentForm->token);
            /** @var Source $source */
            $source = Source::retrieve($paymentForm->token);

            // If this required 3D secure, let's set the flag for it  and repeat
            if (!empty($source->card->three_d_secure) && $source->card->three_d_secure == 'required') {
                $paymentForm->threeDSecure = true;

                return $this->buildRequestPaymentSource($transaction, $paymentForm, $request);
            }

            return $source;
        }

        throw new PaymentException(Craft::t('commerce-stripe', 'Cannot process the payment at this time'));
    }
}
