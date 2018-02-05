<?php

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\base\Plan as BasePlan;
use craft\commerce\base\PlanInterface;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\base\SubscriptionGateway as BaseGateway;
use craft\commerce\base\SubscriptionResponseInterface;
use craft\commerce\elements\Subscription;
use craft\commerce\errors\PaymentException;
use craft\commerce\errors\SubscriptionException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\subscriptions\CancelSubscriptionForm as BaseCancelSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionPayment;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\subscriptions\SwitchPlansForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\errors\CustomerException;
use craft\commerce\stripe\errors\PaymentSourceException;
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\models\forms\CancelSubscription;
use craft\commerce\stripe\models\Customer as CustomerModel;
use craft\commerce\stripe\models\forms\SwitchPlans;
use craft\commerce\stripe\models\Invoice;
use craft\commerce\stripe\models\Plan;
use craft\commerce\stripe\models\forms\Payment;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\Plugin;
use craft\commerce\stripe\responses\PaymentResponse;
use craft\commerce\stripe\responses\SubscriptionResponse;
use craft\commerce\stripe\web\assets\paymentform\PaymentFormAsset;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\View;
use craft\web\Response as WebResponse;
use Stripe\ApiResource;
use Stripe\Charge;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\Error\Base;
use Stripe\Error\Card as CardError;
use Stripe\Invoice as StripeInvoice;
use Stripe\Plan as StripePlan;
use Stripe\Refund;
use Stripe\Source;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
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
    // TODO Figure out all the logging of errors and critical logging
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
        /** @var Payment $form */
        $requestData = $this->_buildRequestData($transaction);
        $paymentSource = $this->_buildRequestPaymentSource($transaction, $form, $requestData);
        $requestData['capture'] = false;

        if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect')
        {
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
    public function createPaymentSource(BasePaymentForm $sourceData): PaymentSource
    {
        /** @var Payment $sourceData */
        $user = Craft::$app->getUser();

        if ($user->getIsGuest()) {
            $user->loginRequired();
        }

        $stripeCustomer = $this->_getStripeCustomer($user->getIdentity());

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
     */
    public function getPaymentFormModel()
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

            $payment = new SubscriptionPayment([
                'paymentAmount' => $data['amount_due'] / (10 ** $currency->minorUnit),
                'paymentCurrency' => $currency,
                'paymentDate' => $data['date'],
                'paymentReference' => $data['charge'],
            ]);

            $payments[] = $payment;
        }

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

        return Json::encode($plan->jsonSerialize());
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPlans(): array
    {
        /** @var Collection $plans */
        $plans = StripePlan::all();
        $output = [];

        if (\count($plans->data)) {
            foreach ($plans->data as $plan) {
                $plan = $plan->jsonSerialize();
                $output[] = ['reference' => $plan['id'], 'name' => $plan['name']];
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
            } else {
                Craft::warning('Could not decode JSON payload.', 'stripe');
            }
        } catch (\Throwable $exception) {
            Craft::error('Exception while processing webhook: '.$exception->getMessage(), 'stripe');
        }

        $response->data =  'ok';

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        /** @var Payment $form */
        $requestData = $this->_buildRequestData($transaction);
        $paymentSource = $this->_buildRequestPaymentSource($transaction, $form, $requestData);

        if ($paymentSource instanceof Source && $paymentSource->status === 'pending' && $paymentSource->flow === 'redirect')
        {
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
    public function reactivateSubscription(Subscription $subscription): SubscriptionResponseInterface
    {
        /** @var Plan $plan */
        $plan = $subscription->getPlan();

        $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
        $stripeSubscription->items = [[
            'id' => $stripeSubscription->items->data[0]->id,
            'plan' => $plan->reference,
        ]];

        return $this->_createSubscriptionResponse($stripeSubscription->save());
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction, string $reference): RequestResponseInterface
    {
        try {
            $refund = Refund::create(['charge' => $reference], ['idempotency_key' => 'refund_'.$reference]);

            return $this->_createPaymentResponseFromApiResource($refund);
        } catch (\Exception $exception) {
            return $this->_createPaymentResponseFromError($exception);
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
        $stripeSubscription->items = [[
            'id' => $stripeSubscription->items->data[0]->id,
            'plan' => $plan->reference,
        ]];
        $stripeSubscription->prorate = (bool) $parameters->prorate;

        $response = $this->_createSubscriptionResponse($stripeSubscription->save());

        if ($parameters->billImmediately) {
            $invoice = StripeInvoice::create(array(
                'customer' => $stripeSubscription->customer,
                'subscription' => $stripeSubscription->id
            ));
        }

        return $response;
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
     * @param Payment     $paymentForm
     * @param array       $request
     *
     * @return Source
     * @throws PaymentException if unexpected payment information encountered
     */
    private function _buildRequestPaymentSource(Transaction $transaction, Payment $paymentForm, array $request)
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
     * @return PaymentResponse
     */
    private function _createPaymentResponseFromApiResource(ApiResource $resource): PaymentResponse
    {
        $data = $resource->jsonSerialize();

        return new PaymentResponse($data);
    }

    /**
     * @inheritdoc
     * @throws SubscriptionException if there was a problem subscribing to the plan
     */
    public function subscribe(User $user, BasePlan $plan, SubscriptionForm $parameters): SubscriptionResponseInterface
    {
        try {
            $stripeCustomer = $this->_getStripeCustomer($user);
        } catch (CustomerException $exception) {
            Craft::warning($exception->getMessage(), 'stripe');

            throw new SubscriptionException(Craft::t('commerce', 'Unable to subscribe at this time.'));
        }

        $sources = $stripeCustomer->sources->all();

        if (\count($sources->data) === 0) {
            throw new PaymentSourceException(Craft::t('commerce', 'No payment sources are saved to use for subscriptions.'));
        }

        try {
            $subscription = StripeSubscription::create([
                'customer' => $stripeCustomer->id,
                'items' => [['plan' => $plan->reference]],
                'trial_period_days' => $parameters->trialDays
            ]);
        } catch (\Throwable $exception) {
            Craft::warning($exception->getMessage(), 'stripe');

            throw new SubscriptionException(Craft::t('commerce', 'Unable to subscribe at this time.'));
        }

        return $this->_createSubscriptionResponse($subscription);
    }

    /**
     * Create a Response object from an Exception.
     *
     * @param \Exception $exception
     *
     * @return PaymentResponse
     * @throws \Exception
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
            $data['message'] = $body['error']['message'];
            $data['code'] = $body['error']['code'] ?? $body['error']['type'];
        } else {
            throw $exception;
        }

        return new PaymentResponse($data);
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
     * Get the Stripe customer for a User.
     *
     * @param User $user
     *
     * @return Customer
     * @throws CustomerException if wasn't able to create or retrieve Stripe Customer.
     */
    private function _getStripeCustomer(User $user): Customer
    {
        try {
            $customers = StripePlugin::getInstance()->getCustomers();
            $customer = $customers->getCustomer($this->id, $user->id);

            if (!$customer) {
                $stripeCustomer = Customer::create([
                    'description' => Craft::t('commerce-stripe', 'Customer for Craft user with ID {id}', ['id' => $user->id]),
                    'email' => $user->email
                ]);

                $customerModel = new CustomerModel([
                    'userId' => $user->id,
                    'gatewayId' => $this->id,
                    'reference' => $stripeCustomer->id,
                    'response' => $stripeCustomer->jsonSerialize()
                ]);

                $customers->saveCustomer($customerModel);
            } else {
                $stripeCustomer = Customer::retrieve($customer->reference);
            }
        } catch (\Exception $exception) {
            throw new CustomerException('Could not fetch Stripe customer: '.$exception->getMessage());
        }

        return $stripeCustomer;
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
        $subscription->isExpired = true;
        $subscription->dateExpired = Db::prepareDateForDb(new \DateTime());

        Craft::$app->getElements()->saveElement($subscription);
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
        $isCanceled = $data['data']['object']['canceled_at'];

        $subscription = Subscription::find()->reference($stripeSubscription['id'])->one();

        // See if we care about this subscription at all
        if ($subscription) {
            $subscription->isCanceled = (bool) $isCanceled;
            $subscription->dateCanceled = $isCanceled ? DateTimeHelper::toDateTime($isCanceled) : null;
            $subscription->nextPaymentDate = DateTimeHelper::toDateTime($data['data']['object']['current_period_end']);

            $planHandle = $isCanceled = $data['data']['object']['plan']['id'];
            $plan = Commerce::getInstance()->getPlans()->getPlanByHandle($planHandle);

            if ($plan && $plan->id !== $subscription->planId) {
                $subscription->planId = $plan->id;
            } else {
                $this->_importantLog($subscription->reference.' was switched to a plan on Stripe that does not exist on this Site. (event "'.$data['id'].'")');
            }

            Craft::$app->getElements()->saveElement($subscription);
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

        if (Plugin::getInstance()->getSettings()->chargeInvoicesImmediately && empty($stripeInvoice['paid'])) {
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
     * @throws \Throwable
     */
    private function _handleInvoiceSucceededEvent(array $data)
    {
        $stripeInvoice = $data['data']['object'];

        // Sanity check
        if (!$stripeInvoice['paid']) {
            return;
        }

        $stripeSubscription = $stripeInvoice['subscription'];
        $subscription = Subscription::find()->reference($stripeSubscription)->one();

        if (!$subscription) {
            Craft::warning('Subscription with the reference “'.$stripeSubscription.'” not found when processing webhook '.$data['id'], 'stripe');

            return;
        }

        $invoice = new Invoice();
        $invoice->subscriptionId = $subscription->id;
        $invoice->reference = $stripeInvoice['id'];
        $invoice->invoiceData = $stripeInvoice;
        StripePlugin::getInstance()->getInvoices()->saveInvoice($invoice);

        $lineItems = $stripeInvoice['lines']['data'];

        // Find the relevant line item and update subscription end date
        foreach ($lineItems as $lineItem) {
            if ($lineItem['id'] === $stripeSubscription) {

                $subscription->nextPaymentDate = DateTimeHelper::toDateTime($lineItem['period']['end']);
                Craft::$app->getElements()->saveElement($subscription);
                return;
            }
        }
    }

    /**
     * Handle 3D Secure related event.
     *
     * @param array $data
     *
     * @return void
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
     * Handle Plan events
     *
     * @param array $data
     *
     * @return void
     */
    private function _handlePlanEvent(array $data)
    {
        $planService = Commerce::getInstance()->getPlans();

        if ($data['type'] == 'plan.deleted') {
            $plans = $planService->getAllPlans();

            foreach ($plans as $plan) {
                if ($plan->reference === $data['data']['object']['id']) {
                    $planService->archivePlanById($plan->id);

                    // TODO probably going to rename this and going to need a place to view these.
                    $this->_importantLog($plan->name.' was archived because the corresponding plan was deleted on Stripe. (event "'.$data['id'].'")');
                }
            }
        }
    }

    private function _importantLog($message) {
        Craft::trace($message, 'stripe');
    }
}
