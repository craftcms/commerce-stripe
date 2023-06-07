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
use craft\commerce\stripe\events\BuildGatewayRequestEvent;
use craft\commerce\stripe\events\PaymentIntentConfirmationEvent;
use craft\commerce\stripe\events\PaymentIntentRequestEvent;
use craft\commerce\stripe\events\SubscriptionRequestEvent;
use craft\commerce\stripe\models\forms\payment\PaymentIntent as PaymentIntentForm;
use craft\commerce\stripe\models\forms\Subscription as SubscriptionForm;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\CheckoutSessionResponse;
use craft\commerce\stripe\responses\PaymentIntentResponse;
use craft\commerce\stripe\web\assets\elementsform\ElementsFormAsset;
use craft\commerce\stripe\web\assets\intentsform\IntentsFormAsset;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\View;
use Exception;
use Stripe\Card;
use Stripe\PaymentIntent;
use Throwable;
use yii\base\NotSupportedException;
use function count;

/**
 * This class represents the Stripe Payment Intents gateway
 *
 * @property-read null|string $settingsHtml
 * @property bool $sendReceiptEmail
 * @property string $apiKey
 * @property string $publishableKey
 * @property string $signingSecret
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 **/
class PaymentIntents extends BaseGateway
{

    public const PAYMENT_FORM_TYPE_CARD = 'card'; // default
    public const PAYMENT_FORM_TYPE_CHECKOUT = 'checkout';
    public const PAYMENT_FORM_TYPE_ELEMENTS = 'elements';

    /**
     * @var string
     */
    public string $_formType = 'card';

    /**
     * @event BeforeConfirmPaymentIntent The event that is triggered before a PaymentIntent is confirmed
     */
    public const EVENT_BEFORE_CONFIRM_PAYMENT_INTENT = 'beforeConfirmPaymentIntent';


    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce-stripe', 'Stripe');
    }

    /**
     * @return string
     */
    public function getFormType(): string
    {
        return $this->_formType;
    }

    /**
     * @param string $formType
     * @return void
     */
    public function setFormType(string $formType): void
    {
        $this->_formType = $formType;
    }

    /**
     * @inheritDoc
     */
    public function showPaymentFormSubmitButton(): bool
    {
        return $this->getFormType() == self::PAYMENT_FORM_TYPE_CHECKOUT;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params): ?string
    {
        $defaults = [
            'gateway' => $this,
            'handle' => $this->handle,
            'appearance' => [
                'theme' => 'stripe'
            ],
            'layout' => [
                'type' => 'tabs',
            ],
            'submitButtonText' => Craft::t('commerce', 'Pay'),
            'submitButtonClasses' => '',
            'errorMessageClasses' => '',
            'paymentFormType' => $this->getFormType(),
            'paymentMethodTypes' => ['card'],
        ];

        $params = array_merge($defaults, $params);

        // Set it in memory on the gateway for this request
        $this->setFormType($params['paymentFormType']);

        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerScript('', View::POS_END, ['src' => 'https://js.stripe.com/v3/']); // we need this to load at end of body

        if ($this->getFormType() == self::PAYMENT_FORM_TYPE_CHECKOUT) {
            $html = $view->renderTemplate('commerce-stripe/paymentForms/checkoutForm', $params);;
        } else {
            $view->registerAssetBundle(ElementsFormAsset::class);
            $html = $view->renderTemplate('commerce-stripe/paymentForms/elementsForm', $params);
        }

        $view->setTemplateMode($previousMode);

        return $html;
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        try {
            $intent = $this->getStripeClient()->paymentIntents->retrieve($reference);
            $intent->capture([], ['idempotency_key' => $reference]);

            return $this->createPaymentResponseFromApiResource($intent);
        } catch (Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new PaymentIntentForm();
    }

    /**
     * @inheritdoc
     */
    public function getResponseModel(mixed $data): RequestResponseInterface
    {
        return new PaymentIntentResponse($data);
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $data = Json::decodeIfJson($transaction->response);

        if ($data['object'] == 'payment_intent') {
            $paymentIntent = $this->getStripeClient()->paymentIntents->retrieve($data['id']);
        } else {
            // Likely a checkout object
            $checkoutSession = $this->getStripeClient()->checkout->sessions->retrieve($data['id']);
            $paymentIntent = $checkoutSession['payment_intent'];
            $paymentIntent = $this->getStripeClient()->paymentIntents->retrieve($paymentIntent);
        }

        return $this->createPaymentResponseFromApiResource($paymentIntent);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-stripe/gatewaySettings/intentsSettings', ['gateway' => $this]);
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

        // Are we dealing with a transaction that was created under the previous 'Charge' gateway?
        if (substr($transaction->reference, 0, 3) === "ch_") {
            try {
                $request = [
                    'charge' => $transaction->reference,
                    'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
                ];
                $refund = $this->getStripeClient()->refunds->create($request);

                return $this->createPaymentResponseFromApiResource($refund);
            } catch (Exception $exception) {
                return $this->createPaymentResponseFromError($exception);
            }
        }

        $stripePaymentIntent = $this->getStripeClient()->paymentIntents->retrieve($transaction->reference);

        try {
            if ($stripePaymentIntent->status == 'succeeded') {
                $refund = $this->getStripeClient()->refunds->create([
                    'payment_intent' => $stripePaymentIntent->id,
                    'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
                ]);

                return $this->createPaymentResponseFromApiResource($refund);
            }
        } catch (Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }

        return $this->createPaymentResponseFromError(new Exception('Unable to refund payment intent.'));
    }

    /**
     * @inheritdoc
     */
    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        /** @var PaymentForm $sourceData */
        try {
            $stripeCustomer = $this->getStripeCustomer($customerId);
            $paymentMethod = $this->getStripeClient()->paymentMethods->retrieve($sourceData->paymentMethodId);
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

            $response = $stripeResponse->toJSON();

            return new PaymentSource([
                'customerId' => $customerId,
                'gatewayId' => $this->id,
                'token' => $stripeResponse->id,
                'response' => $response ?: '',
                'description' => $description,
            ]);
        } catch (Throwable $exception) {
            throw new PaymentSourceException($exception->getMessage());
        }
    }

    /**
     * @inheritdoc
     * @throws SubscriptionException if there was a problem subscribing to the plan
     */
    public function subscribe(User $user, BasePlan $plan, BaseSubscriptionForm $parameters): SubscriptionResponseInterface
    {
        /** @var SubscriptionForm $parameters */
        $customer = StripePlugin::getInstance()->getCustomers()->getCustomer($this->id, $user);
        $paymentMethods = $this->getStripeClient()->paymentMethods->all(['customer' => $customer->reference, 'type' => 'card']);

        if (count($paymentMethods->data) === 0) {
            throw new PaymentSourceException(Craft::t('commerce-stripe', 'No payment sources are saved to use for subscriptions.'));
        }

        $subscriptionParameters = [
            'customer' => $customer->reference,
            'items' => [['plan' => $plan->reference]],
        ];

        if ($parameters->trialDays !== null) {
            $subscriptionParameters['trial_period_days'] = (int)$parameters->trialDays;
        } elseif ($parameters->trialEnd !== null) {
            $subscriptionParameters['trial_end'] = (int)$parameters->trialEnd;
        } else {
            $subscriptionParameters['trial_from_plan'] = true;
        }

        $subscriptionParameters['expand'] = ['latest_invoice.payment_intent'];

        $event = new SubscriptionRequestEvent([
            'parameters' => $subscriptionParameters,
        ]);

        $this->trigger(self::EVENT_BEFORE_SUBSCRIBE, $event);

        try {
            $subscription = $this->getStripeClient()->subscriptions->create($event->parameters);
        } catch (Throwable $exception) {
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
        try {
            $paymentMethod = $this->getStripeClient()->paymentMethods->retrieve($token);
            $paymentMethod->detach();
        } catch (Throwable $throwable) {
            // Assume deleted.
        }

        return true;
    }


    /**
     * @inheritdoc
     */
    public function getBillingIssueResolveFormHtml(Subscription $subscription): string
    {
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
    public function handleWebhook(array $data): void
    {
//        switch ($data['type']) {
//            case 'invoice.payment_failed':
//                $this->handleInvoiceFailed($data);
//                break;
//        }

        parent::handleWebhook($data);
    }

    /**
     * @param Transaction $transaction
     * @param int $amount
     * @param bool $capture
     * @param array $metadata
     * @param ?User $user
     * @return CheckoutSessionResponse
     * @throws \Stripe\Exception\ApiErrorException
     * @throws \craft\commerce\stripe\errors\CustomerException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     */
    public function handleCheckoutRequest(Transaction $transaction, int $amount, bool $capture, array $metadata, ?User $user): CheckoutSessionResponse
    {
        $lineItems = [];
        $lineItems[] = [
            'price_data' => [
                'currency' => $transaction->paymentCurrency,
                'unit_amount' => $amount,
                'tax_behavior' => 'unspecified',
                'product_data' => [
                    'name' => Craft::$app->getSites()->getCurrentSite()->name . ' Order #' . $transaction->getOrder()->shortNumber,
                    'metadata' => [
                        'order_id' => $transaction->getOrder()->id,
                        'order_number' => $transaction->getOrder()->number,
                        'order_short_number' => $transaction->getOrder()->shortNumber,
                    ],
                ],
            ],
            'adjustable_quantity' => [
                'enabled' => false,
            ],
            'quantity' => 1,
        ];

        $paymentIntentData = [
            'capture_method' => $capture ? 'automatic' : 'manual',
        ];

        $data = [
            'cancel_url' => $transaction->getOrder()->cancelUrl,
            'success_url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash]),
            'mode' => 'payment',
            'client_reference_id' => $transaction->hash,
            'line_items' => $lineItems,
            'metadata' => $metadata,
            'allow_promotion_codes' => false,
            'payment_intent_data' => $paymentIntentData,
        ];

        $orderCustomer = $transaction->getOrder()->getCustomer();
        if ($user && $orderCustomer && $user->id == $orderCustomer->id) {
            $data['customer'] = StripePlugin::getInstance()->getCustomers()->getCustomer($this->id, $transaction->getOrder()->getCustomer())->reference;
        } else {
            $data['customer_email'] = $transaction->getOrder()->email;
        }

        $event = new BuildGatewayRequestEvent([
            'transaction' => $transaction,
            'request' => $data,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BUILD_GATEWAY_REQUEST)) {
            $this->trigger(self::EVENT_BUILD_GATEWAY_REQUEST, $event);
        }

        $session = $this->getStripeClient()->checkout->sessions->create($event->request);

        return new CheckoutSessionResponse($session->toArray());
    }

    /**
     * @inheritdoc
     */
    protected function authorizeOrPurchase(Transaction $transaction, PaymentIntentForm|BasePaymentForm $form, bool $capture = true): RequestResponseInterface
    {
        // When we are using the legacy payment flow, we will need to immediately confirm the payment intent later
        $immediatelyConfirmLegacy = false;

        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($transaction->paymentCurrency);
        $user = Craft::$app->getUser()->getIdentity();

        // This is the metadata that will be sent to Stripe for checkout and payment intents
        $metadata = [
            'order_id' => $transaction->getOrder()->id,
            'order_number' => $transaction->getOrder()->number,
            'order_short_number' => $transaction->getOrder()->shortNumber,
            'transaction_id' => $transaction->id,
            'transaction_reference' => $transaction->hash,
            'description' => Craft::t('commerce-stripe', 'Order') . ' #' . $transaction->orderId,
        ];

        $appRequest = Craft::$app->getRequest();
        if (!$appRequest->getIsConsoleRequest()) {
            $metadata['client_ip'] = $appRequest->getUserIP();
        }

        // Normalized amount for Stripe into minor units
        $amount = $transaction->paymentAmount * (10 ** $currency->minorUnit);

        if ($form->paymentFormType == self::PAYMENT_FORM_TYPE_CHECKOUT) {
            return $this->handleCheckoutRequest($transaction, $amount, $capture, $metadata, $user);
        }

        // Base information for the payment intent
        $paymentIntentData = [
            'amount' => $amount,
            'currency' => $transaction->paymentCurrency,
            'confirm' => false,
            'metadata' => $metadata,
            'capture_method' => $capture ? 'automatic' : 'manual',
        ];

        /**
         * The previous version of the Stripe plugin accepted a payment method ID on initial
         * payment intent creation. We can attach it to the payment intent
         * before creating it, and we will immediately confirm it later in this function to match the previous behavior.
         */
        if ($form->paymentMethodId) {
            $paymentIntentData['payment_method'] = $form->paymentMethodId;
            $immediatelyConfirmLegacy = true;
        }

        // Which payment methods are allowed on the payment intent
        if ($form->paymentFormType == self::PAYMENT_FORM_TYPE_ELEMENTS && $form->paymentMethodTypes) {
            $paymentIntentData['payment_method_types'] = $form->paymentMethodTypes;
        } elseif ($form->paymentFormType == self::PAYMENT_FORM_TYPE_CARD) {
            $paymentIntentData['payment_method_types'] = ['card'];
        } else {
            $paymentIntentData['automatic_payment_methods'] = ['enabled' => true];
        }

        // Add the receipt email if enabled
        if ($this->sendReceiptEmail) {
            $paymentIntentData['receipt_email'] = $transaction->getOrder()->getEmail();
        }

        // Add current customer if the user is logged in
        $orderCustomer = $transaction->getOrder()->getCustomer();
        if ($user && $orderCustomer && $user->id == $orderCustomer->id) {
            $paymentIntentData['customer'] = StripePlugin::getInstance()->getCustomers()->getCustomer($this->id, $transaction->getOrder()->getCustomer())->reference;
        }

        $event = new BuildGatewayRequestEvent([
            'transaction' => $transaction,
            'request' => $paymentIntentData,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BUILD_GATEWAY_REQUEST)) {
            $this->trigger(self::EVENT_BUILD_GATEWAY_REQUEST, $event);

            // Do not allow these to be modified by event handlers
            $event->request['amount'] = $paymentIntentData['amount'];
            $event->request['currency'] = $paymentIntentData['currency'];
        }

        $paymentIntent = $this->getStripeClient()->paymentIntents->create($event->request);

        if ($immediatelyConfirmLegacy) {
            // Mutates the payment intent that has been confirmed
            $this->_confirmPaymentIntent($paymentIntent, $transaction);
        }

        return new PaymentIntentResponse($paymentIntent->toArray());
    }

    /**
     * Confirm a payment intent and set the return URL.
     *
     * @param PaymentIntent $stripePaymentIntent
     * @param Transaction $transaction
     * @throws \Stripe\Exception\ApiErrorException
     */
    private function _confirmPaymentIntent(PaymentIntent $stripePaymentIntent, Transaction $transaction): void
    {
        $parameters = [
            'return_url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash]),
        ];

        $event = new PaymentIntentConfirmationEvent([
            'parameters' => $parameters,
        ]);

        $this->trigger(self::EVENT_BEFORE_CONFIRM_PAYMENT_INTENT, $event);

        $stripePaymentIntent->confirm($event->parameters);
    }

    /**
     * @inheritdoc
     */
    public function supportsPaymentSources(): bool
    {
        if ($this->_formType == self::PAYMENT_FORM_TYPE_CARD) {
            return true;
        }

        return parent::supportsPaymentSources();
    }
}
