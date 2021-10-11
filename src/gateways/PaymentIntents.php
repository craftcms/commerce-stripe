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
use craft\commerce\errors\SubscriptionException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\subscriptions\SubscriptionForm as BaseSubscriptionForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\base\SubscriptionGateway as BaseGateway;
use craft\commerce\stripe\errors\PaymentSourceException;
use craft\commerce\stripe\events\SubscriptionRequestEvent;
use craft\commerce\stripe\models\forms\payment\PaymentIntent as PaymentForm;
use craft\commerce\stripe\models\forms\Subscription as SubscriptionForm;
use craft\commerce\stripe\models\PaymentIntent as PaymentIntentModel;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\PaymentIntentResponse;
use craft\commerce\stripe\web\assets\intentsform\IntentsFormAsset;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\View;
use Stripe\Card;
use Stripe\Charge as StripeCharge;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\Subscription as StripeSubscription;
use yii\base\NotSupportedException;

/**
 * This class represents the Stripe Payment Intents gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 **/
class PaymentIntents extends BaseGateway
{
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

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce-stripe', 'Stripe Payment Intents');
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
            'scenario' => 'payment',
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

        $view->registerScript('', View::POS_END, ['src' => 'https://js.stripe.com/v3/']); // we need this to load at end of body
        $view->registerAssetBundle(IntentsFormAsset::class);

        $html = $view->renderTemplate('commerce-stripe/paymentForms/intentsForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        $this->configureStripeClient();
        try {
            /** @var PaymentIntent $intent */
            $intent = PaymentIntent::retrieve($reference);
            $intent->capture([], ['idempotency_key' => $reference]);

            return $this->createPaymentResponseFromApiResource($intent);
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
    public function getResponseModel($data): RequestResponseInterface
    {
        $this->configureStripeClient();
        return new PaymentIntentResponse($data);
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $this->configureStripeClient();
        $paymentIntentReference = Craft::$app->getRequest()->getParam('payment_intent');
        $stripePaymentIntent = PaymentIntent::retrieve($paymentIntentReference);

        // Update the intent with the latest.
        $paymentIntentsService = StripePlugin::getInstance()->getPaymentIntents();

        $paymentIntent = $paymentIntentsService->getPaymentIntentByReference($paymentIntentReference);

        // Make sure we have the payment intent before we attempt to do anything with it.
        if ($paymentIntent) {
            $paymentIntent->intentData = Json::encode($stripePaymentIntent->toArray());
            $paymentIntentsService->savePaymentIntent($paymentIntent);
        }

        $intentData = $stripePaymentIntent->toArray();

        if (!empty($intentData['payment_method'])) {
            try {
                $this->_confirmPaymentIntent($stripePaymentIntent, $transaction);
            } catch (\Exception $exception) {
                return $this->createPaymentResponseFromError($exception);
            }
        }

        return $this->createPaymentResponseFromApiResource($stripePaymentIntent);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        $this->configureStripeClient();
        return Craft::$app->getView()->renderTemplate('commerce-stripe/gatewaySettings/intentsSettings', ['gateway' => $this]);
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

        // Are we dealing with a transaction that was created under the previous 'Charge' gateway?
        if (substr($transaction->reference, 0, 3) === "ch_") {
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

        $stripePaymentIntent = PaymentIntent::retrieve($transaction->reference);
        $paymentIntentsService = StripePlugin::getInstance()->getPaymentIntents();

        $paymentIntent = $paymentIntentsService->getPaymentIntentByReference($transaction->reference);

        try {
            /** @var StripeCharge $charge */
            $charge = $stripePaymentIntent->charges->data[0];
            $refund = Refund::create([
                'charge' => $charge->id,
                'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
            ]);

            // Entirely possible there's no payment intent stored locally.
            // Most likely case being a guest user purchase for which we're unable
            // to keep track of Stripe customer.
            if ($paymentIntent) {
                // Fetch the new intent data
                $stripePaymentIntent = PaymentIntent::retrieve($transaction->reference);
                $paymentIntent->intentData = Json::encode($stripePaymentIntent->toArray());
                $paymentIntentsService->savePaymentIntent($paymentIntent);
            }

            return $this->createPaymentResponseFromApiResource($refund);
        } catch (\Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function createPaymentSource(BasePaymentForm $sourceData, int $userId): PaymentSource
    {
        $this->configureStripeClient();
        /** @var PaymentForm $sourceData */
        try {
            $stripeCustomer = $this->getStripeCustomer($userId);
            $paymentMethod = PaymentMethod::retrieve($sourceData->paymentMethodId);
            $stripeResponse = $paymentMethod->attach(['customer' => $stripeCustomer->id]);

            // Set as default.
            $stripeCustomer->invoice_settings['default_payment_method'] = $paymentMethod->id;
            $stripeCustomer->save();

            switch ($stripeResponse->type) {
                case 'card':
                    /** @var Card $card */
                    $card = $stripeResponse->card;
                    $description = Craft::t('commerce-stripe', '{cardType} ending in ••••{last4}', ['cardType' => StringHelper::upperCaseFirst($card->brand), 'last4' => $card->last4]);
                    break;
                default:
                    $description = $stripeResponse->type;
            }

            $paymentSource = new PaymentSource([
                'userId' => $userId,
                'gatewayId' => $this->id,
                'token' => $stripeResponse->id,
                'response' => $stripeResponse->toArray(),
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
    public function subscribe(User $user, BasePlan $plan, BaseSubscriptionForm $parameters): SubscriptionResponseInterface
    {
        $this->configureStripeClient();
        /** @var SubscriptionForm $parameters */
        $customer = StripePlugin::getInstance()->getCustomers()->getCustomer($this->id, $user);
        $paymentMethods = PaymentMethod::all(['customer' => $customer->reference, 'type' => 'card']);

        if (!StripePlugin::getInstance()->getSettings()->allowSubscriptionWithoutPaymentMethod && \count($paymentMethods->data) === 0) {
            throw new PaymentSourceException(Craft::t('commerce-stripe', 'No payment sources are saved to use for subscriptions.'));
        }

        $subscriptionParameters = [
            'customer' => $customer->reference,
            'items' => [['plan' => $plan->reference]],
        ];

        if ($parameters->trialDays !== null) {
            $subscriptionParameters['trial_period_days'] = (int)$parameters->trialDays;
        } else if ($parameters->trialEnd !== null) {
            $subscriptionParameters['trial_end'] = (int)$parameters->trialEnd;;
        } else {
            $subscriptionParameters['trial_from_plan'] = true;
        }

        $subscriptionParameters['expand'] = ['latest_invoice.payment_intent'];

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
     */
    public function deletePaymentSource($token): bool
    {
        $this->configureStripeClient();
        try {
            /** @var PaymentMethod $paymentMethod */
            $paymentMethod = PaymentMethod::retrieve($token);
            $paymentMethod->detach();
        } catch (\Throwable $throwable) {
            // Assume deleted.
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function getBillingIssueDescription(Subscription $subscription): string
    {
        $this->configureStripeClient();
        $subscriptionData = $this->_getExpandedSubscriptionData($subscription);
        $intentData = $subscriptionData['latest_invoice']['payment_intent'];

        if (in_array($subscriptionData['status'], ['incomplete', 'past_due', 'unpaid'])) {
            switch ($intentData['status']) {
                case 'requires_payment_method':
                    return $subscription->hasStarted ? Craft::t('commerce-stripe', 'To resume the subscription, please provide a valid payment method.') : Craft::t('commerce-stripe', 'To start the subscription, please provide a valid payment method.');
                case 'requires_action':
                    return $subscription->hasStarted ? Craft::t('commerce-stripe', 'To resume the subscription, please complete 3DS authentication.') : Craft::t('commerce-stripe', 'To start the subscription, please complete 3DS authentication.');
            }
        }

        return '';
    }

    /**
     * @inheritdoc
     */
    public function getBillingIssueResolveFormHtml(Subscription $subscription): string
    {
        $this->configureStripeClient();
        $subscriptionData = $this->_getExpandedSubscriptionData($subscription);
        $intentData = $subscriptionData['latest_invoice']['payment_intent'];

        if (in_array($subscriptionData['status'], ['incomplete', 'past_due', 'unpaid'])) {
            $clientSecret = $intentData['client_secret'];
            switch ($intentData['status']) {
                case 'requires_payment_method':
                case 'requires_confirmation':
                    return $this->getPaymentFormHtml(['clientSecret' => $clientSecret]);
                case 'requires_action':
                    return $this->getPaymentFormHtml(['clientSecret' => $clientSecret, 'scenario' => '3ds']);
            }
        }

        return '';
    }

    /**
     * @inheritdoc
     */
    public function getHasBillingIssues(Subscription $subscription): bool
    {
        $this->configureStripeClient();
        $subscription = $this->refreshSubscriptionData($subscription);
        $subscriptionData = $subscription->getSubscriptionData();
        $intentData = $subscriptionData['latest_invoice']['payment_intent'];

        return in_array($subscriptionData['status'], ['incomplete', 'past_due', 'unpaid']) && in_array($intentData['status'], ['requires_payment_method', 'requires_confirmation', 'requires_action']);
    }

    /**
     * @inheritdoc
     */
    public function handleWebhook(array $data)
    {
        $this->configureStripeClient();
        switch ($data['type']) {
            case 'invoice.payment_failed':
                $this->handleInvoiceFailed($data);
                break;
        }

        parent::handleWebhook($data);
    }

    /**
     * Handle a failed invoice by updating the subscription data for the subscription it failed.
     *
     * @param array $data
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    protected function handleInvoiceFailed(array $data)
    {
        $this->configureStripeClient();
        $stripeInvoice = $data['data']['object'];

        // Sanity check
        if ($stripeInvoice['paid']) {
            return;
        }

        $subscriptionReference = $stripeInvoice['subscription'] ?? null;

        if (!$subscriptionReference || !($subscription = Subscription::find()->anyStatus()->reference($subscriptionReference)->one())) {
            Craft::warning('Subscription with the reference “' . $subscriptionReference . '” not found when processing webhook ' . $data['id'], 'stripe');

            return;
        }

        $this->refreshSubscriptionData($subscription);
    }

    /**
     * @inheritdoc
     */
    protected function handleSubscriptionUpdated(array $data)
    {
        $this->configureStripeClient();
        // Fetch expanded data
        $stripeSubscription = StripeSubscription::retrieve([
            'id' => $data['data']['object']['id'],
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        // And nonchalantly replace it, before calling parent.
        $data['data']['object'] = $stripeSubscription->toArray();

        parent::handleSubscriptionUpdated($data);
    }

    /**
     * @inheritdoc
     */
    protected function authorizeOrPurchase(Transaction $transaction, BasePaymentForm $form, bool $capture = true): RequestResponseInterface
    {
        $this->configureStripeClient();
        /** @var PaymentForm $form */
        $requestData = $this->buildRequestData($transaction);
        $paymentMethodId = $form->paymentMethodId;

        $customer = null;
        $paymentIntent = null;

        $stripePlugin = StripePlugin::getInstance();

        if ($form->customer) {
            $requestData['customer'] = $form->customer;
            $customer = $stripePlugin->getCustomers()->getCustomerByReference($form->customer);
        } else if ($user = $transaction->getOrder()->getUser()) {
            $customer = $stripePlugin->getCustomers()->getCustomer($this->id, $user);
            $requestData['customer'] = $customer->reference;
        }

        $requestData['payment_method'] = $paymentMethodId;

        $paymentIntentService = $stripePlugin->getPaymentIntents();

        try {
            // If this is a customer that's logged in, attempt to continue the timeline
            if ($customer) {
                $paymentIntent = $paymentIntentService->getPaymentIntent($this->id, $transaction->orderId, $customer->id);
            }

            // If a payment intent exists, update that.
            if ($paymentIntent) {
                $stripePaymentIntent = PaymentIntent::update($paymentIntent->reference, $requestData, ['idempotency_key' => $transaction->hash]);
            } else {
                $requestData['capture_method'] = $capture ? 'automatic' : 'manual';
                $requestData['confirmation_method'] = 'manual';
                $requestData['confirm'] = false;

                $stripePaymentIntent = PaymentIntent::create($requestData, ['idempotency_key' => $transaction->hash]);

                if ($customer) {
                    $paymentIntent = new PaymentIntentModel([
                        'orderId' => $transaction->orderId,
                        'customerId' => $customer->id,
                        'gatewayId' => $this->id,
                        'reference' => $stripePaymentIntent->id,
                    ]);
                }
            }

            if ($paymentIntent) {
                // Save data before confirming.
                $paymentIntent->intentData = Json::encode($stripePaymentIntent->toArray());
                $paymentIntentService->savePaymentIntent($paymentIntent);
            }

            $this->_confirmPaymentIntent($stripePaymentIntent, $transaction);

            return $this->createPaymentResponseFromApiResource($stripePaymentIntent);
        } catch (\Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }
    }

    /**
     * Refresh a subscription's data.
     *
     * @param Subscription $subscription
     * @return Subscription
     */
    protected function refreshSubscriptionData(Subscription $subscription)
    {
        $this->configureStripeClient();
        $stripeSubscription = StripeSubscription::retrieve([
            'id' => $subscription->reference,
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        $subscription->setSubscriptionData($stripeSubscription->toArray());
        $this->setSubscriptionStatusData($subscription);
        Craft::$app->getElements()->saveElement($subscription);

        return $subscription;
    }

    /**
     * Confirm a payment intent and set the return URL.
     *
     * @param PaymentIntent $stripePaymentIntent
     */
    private function _confirmPaymentIntent(PaymentIntent $stripePaymentIntent, Transaction $transaction)
    {
        $this->configureStripeClient();
        $stripePaymentIntent->confirm([
            'return_url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash]),
        ]);
    }

    /**
     * Get the expanded subscription data, including payment intent for latest invoice.
     *
     * @param Subscription $subscription
     * @return array
     */
    private function _getExpandedSubscriptionData(Subscription $subscription): array
    {
        $this->configureStripeClient();
        $subscriptionData = $subscription->getSubscriptionData();

        if (empty($subscriptionData['latest_invoice']['payment_intent'])) {
            $stripeSubscription = StripeSubscription::retrieve([
                'id' => $subscription->reference,
                'expand' => ['latest_invoice.payment_intent'],
            ]);
            $subscriptionData = $stripeSubscription->toArray();
            $subscription->setSubscriptionData($subscriptionData);
        }

        return $subscriptionData;
    }
}
