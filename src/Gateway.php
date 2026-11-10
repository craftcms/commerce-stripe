<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe;

use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Support\Arr;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\HiddenField;
use CraftCms\Cms\Ui\Nodes\TemplateContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\View\Enums\Position;
use CraftCms\Commerce\Customer\Customers as CommerceCustomers;
use CraftCms\Commerce\Helpers\Currency as CurrencyHelper;
use CraftCms\Commerce\Helpers\PaymentForm;
use CraftCms\Commerce\Http\Controllers\PaymentsController as CommercePaymentsController;
use CraftCms\Commerce\Order\Carts;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Currencies;
use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Exceptions\PaymentSourceCreatedLaterException;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;
use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;
use CraftCms\Commerce\Payment\Gateway\Gateway as BaseGateway;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\PaymentCurrencies;
use CraftCms\Commerce\Payment\Payments;
use CraftCms\Commerce\Payment\PaymentSources;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceStripe\Events\GatewayRequestBuilding;
use CraftCms\CommerceStripe\Events\PaymentFormRendering;
use CraftCms\CommerceStripe\Events\PaymentIntentConfirming;
use CraftCms\CommerceStripe\Events\ServerSideConfirming;
use CraftCms\CommerceStripe\Events\SetupIntentRequestBuilding;
use CraftCms\CommerceStripe\Events\WebhookReceived;
use CraftCms\CommerceStripe\Exceptions\CustomerException;
use CraftCms\CommerceStripe\Exceptions\PaymentSourceException;
use CraftCms\CommerceStripe\Forms\PaymentIntentForm;
use CraftCms\CommerceStripe\Responses\CheckoutSessionResponse;
use CraftCms\CommerceStripe\Responses\PaymentIntentResponse;
use CraftCms\CommerceStripe\Responses\ServerConfirmedPaymentIntentResponse;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Override;
use RuntimeException;
use Stripe\ApiResource;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Customer as StripeCustomer;
use Stripe\CustomerCashBalanceTransaction;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;

/**
 * The Stripe gateway, using the Payment Intents API.
 *
 * Customers pay with the Stripe Payment Element, or on a hosted Stripe Checkout page. Stripe reports status changes to
 * the gateway’s webhook.
 */
class Gateway extends BaseGateway
{
    public const string PAYMENT_FORM_TYPE_CHECKOUT = 'checkout';

    public const string PAYMENT_FORM_TYPE_ELEMENTS = 'elements';

    /**
     * The Stripe API version used for all requests.
     */
    public const string STRIPE_API_VERSION = '2026-01-28.clover';

    /**
     * @var string|null The publishable API key. Can be set to an environment variable.
     */
    public ?string $publishableKey = null;

    /**
     * @var string|null The secret API key. Can be set to an environment variable.
     */
    public ?string $apiKey = null;

    /**
     * @var string|null The webhook endpoint’s signing secret. Can be set to an environment variable.
     */
    public ?string $signingSecret = null;

    /**
     * @var bool|string Whether Stripe should email a receipt to the order’s email address. Can be set to an environment
     * variable.
     */
    public bool|string $sendReceiptEmail = false;

    private ?StripeClient $_stripeClient = null;

    #[Override]
    public static function displayName(): string
    {
        return t('Stripe', category: 'commerce-stripe');
    }

    public function getPublishableKey(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->publishableKey) : $this->publishableKey;
    }

    public function setPublishableKey(?string $publishableKey): void
    {
        $this->publishableKey = $publishableKey;
    }

    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->apiKey) : $this->apiKey;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
        $this->_stripeClient = null;
    }

    public function getSigningSecret(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->signingSecret) : $this->signingSecret;
    }

    public function setSigningSecret(?string $signingSecret): void
    {
        $this->signingSecret = $signingSecret;
    }

    public function getSendReceiptEmail(bool $parse = true): bool|string
    {
        return $parse ? (Env::parseBoolean($this->sendReceiptEmail) ?? false) : $this->sendReceiptEmail;
    }

    public function setSendReceiptEmail(bool|string $sendReceiptEmail): void
    {
        $this->sendReceiptEmail = $sendReceiptEmail;
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'publishableKey' => ['required'],
            'apiKey' => ['required'],
        ]);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext()): ?Ui
    {
        return Ui::make([
            Field::make(t('Publishable API Key', category: 'commerce-stripe'))
                ->instructions(t('Your publishable Stripe API key.', category: 'commerce-stripe'))
                ->required()
                ->control(Text::make('publishableKey')
                    ->value($this->publishableKey)
                    ->dir('ltr')
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Secret API Key', category: 'commerce-stripe'))
                ->instructions(t('Your Stripe API key.', category: 'commerce-stripe'))
                ->required()
                ->control(Text::make('apiKey')
                    ->value($this->apiKey)
                    ->dir('ltr')
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Webhook Signing Secret', category: 'commerce-stripe'))
                ->instructions(t('Your webhook endpoint signing secret.', category: 'commerce-stripe'))
                ->control(Text::make('signingSecret')
                    ->value($this->signingSecret)
                    ->dir('ltr')
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
        ]);
    }

    /**
     * Returns a Stripe client for the gateway’s secret API key.
     */
    public function getStripeClient(): StripeClient
    {
        if ($this->_stripeClient === null) {
            $plugin = app(Plugin::class);

            Stripe::setAppInfo((string)$plugin->name, $plugin->version, $plugin->documentationUrl);
            Stripe::setApiKey($this->getApiKey());
            Stripe::setApiVersion(self::STRIPE_API_VERSION);
            Stripe::setMaxNetworkRetries(3);
            Stripe::setLogger(Log::channel());

            $this->_stripeClient = new StripeClient([
                'api_key' => $this->getApiKey(),
                'stripe_version' => self::STRIPE_API_VERSION,
            ]);
        }

        return $this->_stripeClient;
    }

    #[Override]
    public function showPaymentFormSubmitButton(): bool
    {
        return false;
    }

    #[Override]
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new PaymentIntentForm();
    }

    /**
     * Returns a response wrapping Stripe data.
     *
     * @param array<string, mixed> $data
     */
    public function getResponseModel(mixed $data): RequestResponseInterface
    {
        return new PaymentIntentResponse($data);
    }

    // Payment form
    // -------------------------------------------------------------------------

    /**
     * Returns the payment form params, merged with the defaults.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getPaymentFormParams(array $params): array
    {
        $defaults = [
            'scenario' => 'payment',
            'order' => null,
            'locale' => app()->getLocale(),
            'gateway' => $this,
            'handle' => $this->handle,
            'appearance' => [
                'theme' => 'stripe',
            ],
            'elementOptions' => [
                'layout' => [
                    'type' => 'tabs',
                ],
            ],
            'hiddenClass' => 'hidden',
            'fonts' => [],
            'submitButtonClasses' => '',
            'errorMessageClasses' => '',
            'submitButtonText' => t('Pay', category: 'commerce-stripe'),
            'processingButtonText' => t('Processing…', category: 'commerce-stripe'),
            'paymentFormType' => self::PAYMENT_FORM_TYPE_ELEMENTS,
            'serverSideConfirmation' => false,
            'elementsOptions' => [],
            'billingDetails' => [],
        ];

        /** @var Order|null $order */
        $order = $params['order'] ?? null;
        if ($order && $order->getBillingAddress()) {
            $defaults['elementOptions']['defaultValues'] = [
                'billingDetails' => $this->getBillingDetails($order),
            ];
        }

        $event = new PaymentFormRendering($this, Arr::merge($defaults, $params), $order);
        event($event);

        $params = $event->params;
        $order = $params['order'] ?? $order;

        if ($params['serverSideConfirmation']) {
            // Server-side confirmation needs an order to know the amount and currency to show in the Payment Element.
            if (!$order && !request()->isCpRequest()) {
                $order = app(Carts::class)->getCart();
            }

            if ($order) {
                $params['elementsOptions'] = Arr::merge($this->getDeferredElementsOptions($order), $params['elementsOptions']);
                // Stripe requires every billing detail when the Payment Element is set not to collect them, and Craft
                // addresses don’t have a phone number by default.
                $billingDetails = $order->getBillingAddress() ? array_merge($this->getBillingDetails($order), ['phone' => null]) : [];
                $params['billingDetails'] = Arr::merge($billingDetails, $params['billingDetails']);
            } else {
                $params['serverSideConfirmation'] = false;
            }
        }

        return $params;
    }

    /**
     * Returns the Stripe Payment Element form, or a button that sends the customer to Stripe Checkout.
     *
     * Accepts the params documented in the README, such as `paymentFormType`, `order`, `appearance`,
     * `elementOptions` and `serverSideConfirmation`.
     */
    #[Override]
    public function paymentFormUi(BasePaymentForm $form, array $params = []): ?Ui
    {
        $params = $this->getPaymentFormParams($params);

        if ($params['scenario'] === '') {
            return Ui::make([
                TemplateContent::make('stripe-error', e(t('Commerce Stripe 4.0+ requires a scenario is set on the payment form.', category: 'commerce-stripe'))),
            ]);
        }

        // Stripe.js has to be loaded from js.stripe.com.
        HtmlStack::jsFile('https://js.stripe.com/v3/');

        if ($params['paymentFormType'] === self::PAYMENT_FORM_TYPE_CHECKOUT) {
            if ($form instanceof PaymentIntentForm) {
                $form->paymentFormType = self::PAYMENT_FORM_TYPE_CHECKOUT;
            }

            return Ui::make([
                HiddenField::make('paymentFormType'),
                TemplateContent::make('stripe-checkout', Html::tag('input', '', [
                    'type' => 'submit',
                    'class' => $params['submitButtonClasses'],
                    'value' => $params['submitButtonText'],
                ]))->trusted(),
            ]);
        }

        HtmlStack::js((string)file_get_contents(dirname(__DIR__) . '/resources/js/paymentForm.js'), Position::Ready, 'commerce-stripe-payment-form');

        return Ui::make([
            TemplateContent::make('stripe-payment-elements', $this->getPaymentElementsHtml($params))->trusted(),
        ]);
    }

    /**
     * Returns the container that the payment form JS mounts the Stripe Payment Element in. The JS reads its settings
     * from the container’s data attributes.
     *
     * @param array<string, mixed> $params
     */
    protected function getPaymentElementsHtml(array $params): string
    {
        $hiddenClass = (string)$params['hiddenClass'];

        $html = Html::tag('div', '', [
            'class' => trim("$hiddenClass stripe-error-message {$params['errorMessageClasses']}"),
        ]);
        $html .= Html::tag('div', Html::tag('div', '', ['class' => 'stripe-payment-element']) .
            Html::tag('button', e($params['submitButtonText']), [
                'class' => trim("stripe-payment-elements-submit-button {$params['submitButtonClasses']}"),
            ]), ['class' => 'stripe-payment-form']);

        return Html::tag('div', $html, [
            'class' => trim("stripe-payment-elements-form $hiddenClass"),
            'data-publishablekey' => (string)$this->getPublishableKey(),
            'data-complete-payment-action-url' => Url::actionUrl('commerce/payments/complete-payment'),
            'data-confirm-setup-intent-url' => Url::actionUrl('commerce-stripe/customers/confirm-setup-intent'),
            'data-users-session-info-url' => Url::actionUrl('users/session-info'),
            'data-payment-form-namespace' => PaymentForm::getPaymentFormNamespace((string)$params['handle']),
            'data-gateway-id' => (string)$this->id,
            'data-appearance' => Json::encode($params['appearance']),
            'data-fonts' => Json::encode($params['fonts']),
            'data-element-options' => Json::encode($params['elementOptions']),
            'data-client-scenario' => (string)$params['scenario'],
            'data-processing-button-text' => (string)$params['processingButtonText'],
            'data-hidden-class' => $hiddenClass,
            'data-locale' => (string)($params['locale'] ?? 'auto'),
            'data-server-side-confirmation' => $params['serverSideConfirmation'] ? 'true' : 'false',
            'data-elements-options' => Json::encode($params['elementsOptions']),
            'data-billing-details' => Json::encode($params['billingDetails']),
        ]);
    }

    // Payments
    // -------------------------------------------------------------------------

    #[Override]
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->authorizeOrPurchase($transaction, $form, false);
    }

    #[Override]
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->authorizeOrPurchase($transaction, $form);
    }

    #[Override]
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        return $this->completePurchase($transaction);
    }

    #[Override]
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $data = Json::decodeIfJson($transaction->response);
        $options = ['expand' => ['payment_method']];
        $client = $this->getStripeClient();

        if (($data['object'] ?? null) === PaymentIntent::OBJECT_NAME) {
            $paymentIntent = $client->paymentIntents->retrieve($data['id'], $options);
        } else {
            // A Checkout session
            $session = $client->checkout->sessions->retrieve($data['id']);
            $paymentIntent = $client->paymentIntents->retrieve((string)$session->payment_intent, $options);
        }

        return $this->createPaymentResponseFromApiResource($paymentIntent);
    }

    #[Override]
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        try {
            $intent = $this->getStripeClient()->paymentIntents->capture($reference, [], ['idempotency_key' => $reference]);

            return $this->createPaymentResponseFromApiResource($intent);
        } catch (Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }
    }

    #[Override]
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $reference = (string)$transaction->reference;
        $amount = $this->toMinorUnits($transaction->paymentAmount, (string)$transaction->paymentCurrency);
        $client = $this->getStripeClient();

        // Transactions created by the old Charge gateway reference a charge.
        if (str_starts_with($reference, 'ch_')) {
            try {
                return $this->createPaymentResponseFromApiResource($client->refunds->create([
                    'charge' => $reference,
                    'amount' => $amount,
                ]));
            } catch (Exception $exception) {
                return $this->createPaymentResponseFromError($exception);
            }
        }

        $paymentIntent = $client->paymentIntents->retrieve($reference);

        try {
            if ($paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED) {
                return $this->createPaymentResponseFromApiResource($client->refunds->create([
                    'payment_intent' => $paymentIntent->id,
                    'amount' => $amount,
                ]));
            }
        } catch (Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }

        return $this->getResponseModel([
            'id' => null,
            'code' => '',
            'message' => 'Unable to refund payment intent.',
        ]);
    }

    #[Override]
    public function transactionSupportsRefund(Transaction $transaction): bool
    {
        if ($transaction->response && $transaction->status === TransactionModel::STATUS_SUCCESS) {
            $response = Json::decodeIfJson($transaction->response);

            // Payments funded by a bank transfer to the customer’s cash balance can’t be refunded.
            if (is_array($response)
                && ($response['object'] ?? null) === CustomerCashBalanceTransaction::OBJECT_NAME
                && ($response['type'] ?? null) === CustomerCashBalanceTransaction::TYPE_APPLIED_TO_PAYMENT
            ) {
                return false;
            }
        }

        return parent::transactionSupportsRefund($transaction);
    }

    /**
     * Creates a payment intent for a transaction.
     *
     * @param int $amount The amount in the currency’s minor unit
     * @param array<string, mixed> $metadata
     * @throws ApiErrorException
     * @throws CustomerException
     */
    public function createPaymentIntent(Transaction $transaction, int $amount, array $metadata, bool $capture, PaymentIntentForm $form): PaymentIntent
    {
        $order = $this->getOrder($transaction);

        $request = [
            'amount' => $amount,
            'currency' => $transaction->paymentCurrency,
            'confirm' => false,
            'metadata' => $metadata,
            'capture_method' => $capture ? 'automatic' : 'manual',
            'expand' => ['payment_method'],
        ];

        if (!empty($metadata['description'])) {
            $request['description'] = $metadata['description'];
        }

        $request['automatic_payment_methods'] = ['enabled' => true];

        if ($form->paymentMethodId) {
            $request['payment_method'] = $form->paymentMethodId;
        }

        if ($this->getSendReceiptEmail()) {
            $request['receipt_email'] = $order->getEmail();
        }

        if ($orderCustomer = $order->getCustomer()) {
            // Creates the customer in Stripe if it doesn’t exist yet
            $request['customer'] = app(Customers::class)->getCustomer($this->id, $orderCustomer)->reference;

            if ($form->savePaymentSource) {
                $request['setup_future_usage'] = 'off_session';
            }
        }

        // Confirm the payment intent on creation, with the ConfirmationToken created by Stripe.js
        if ($form->confirmationToken) {
            $confirmationEvent = new PaymentIntentConfirming($this, $transaction, [
                'return_url' => $this->getCompletePaymentUrl($transaction),
                'expand' => ['payment_method'],
            ]);
            event($confirmationEvent);

            $request = array_merge($request, $confirmationEvent->parameters, [
                'confirm' => true,
                'confirmation_token' => $form->confirmationToken,
                // Lets Stripe.js handle any next action (such as 3D Secure) with `stripe.handleNextAction()`
                'use_stripe_sdk' => true,
                // Don’t let listeners change these
                'amount' => $request['amount'],
                'currency' => $request['currency'],
            ]);
        }

        $event = new GatewayRequestBuilding($this, 'payment_intent', $transaction, $request);
        event($event);

        // Don’t let listeners change these
        $event->request['amount'] = $request['amount'];
        $event->request['currency'] = $request['currency'];

        return $this->getStripeClient()->paymentIntents->create($event->request, [
            'idempotency_key' => $transaction->hash,
        ]);
    }

    /**
     * Creates a Checkout session for a transaction.
     *
     * @param int $amount The amount in the currency’s minor unit
     * @param array<string, mixed> $metadata
     * @throws ApiErrorException
     * @throws CustomerException
     */
    public function createCheckoutSession(Transaction $transaction, int $amount, array $metadata, bool $capture, ?User $user): StripeCheckoutSession
    {
        $order = $this->getOrder($transaction);

        $request = [
            'cancel_url' => $order->cancelUrl,
            'success_url' => $this->getCompletePaymentUrl($transaction),
            'mode' => 'payment',
            'client_reference_id' => $transaction->hash,
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $transaction->paymentCurrency,
                        'unit_amount' => $amount,
                        'tax_behavior' => 'unspecified',
                        'product_data' => [
                            'name' => Sites::getCurrentSite()->getName() . ' Order #' . $order->getShortNumber(),
                            'metadata' => [
                                'order_id' => $order->id,
                                'order_number' => $order->number,
                                'order_short_number' => $order->getShortNumber(),
                            ],
                        ],
                    ],
                    'adjustable_quantity' => [
                        'enabled' => false,
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => $metadata,
            'allow_promotion_codes' => false,
            'payment_intent_data' => [
                'capture_method' => $capture ? 'automatic' : 'manual',
            ],
        ];

        if ($orderCustomer = $order->getCustomer()) {
            $request['customer'] = app(Customers::class)->getCustomer($this->id, $orderCustomer)->reference;
        } else {
            $request['customer_email'] = $order->getEmail();
        }

        $event = new GatewayRequestBuilding($this, 'checkout.session', $transaction, $request);
        event($event);

        return $this->getStripeClient()->checkout->sessions->create($event->request);
    }

    /**
     * Makes an authorize or purchase request to Stripe.
     *
     * @param bool $capture Whether funds should be captured immediately
     * @throws ApiErrorException
     * @throws CustomerException
     */
    protected function authorizeOrPurchase(Transaction $transaction, BasePaymentForm $form, bool $capture = true): RequestResponseInterface
    {
        /** @var PaymentIntentForm $form */
        $order = $this->getOrder($transaction);

        // The metadata sent with Checkout sessions and payment intents
        $metadata = [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'order_short_number' => $order->getShortNumber(),
            'transaction_id' => $transaction->id,
            'transaction_reference' => $transaction->hash,
            'description' => t('Order', category: 'commerce-stripe') . ' #' . $transaction->orderId,
        ];

        if (!app()->runningInConsole()) {
            $metadata['client_ip'] = request()->ip();
        }

        $amount = $this->toMinorUnits($transaction->paymentAmount, (string)$transaction->paymentCurrency);

        if ($form->paymentFormType === self::PAYMENT_FORM_TYPE_CHECKOUT) {
            $session = $this->createCheckoutSession($transaction, $amount, $metadata, $capture, currentUserElement());

            return new CheckoutSessionResponse($session->toArray());
        }

        if ($form->confirmationToken) {
            if (!str_starts_with($form->confirmationToken, 'ctoken_')) {
                return $this->getResponseModel([
                    'id' => null,
                    'code' => 'invalid_confirmation_token',
                    'message' => t('Invalid confirmation token.', category: 'commerce-stripe'),
                ]);
            }

            try {
                if (app('events')->hasListeners(ServerSideConfirming::class)) {
                    $confirmationToken = $this->getStripeClient()->confirmationTokens->retrieve($form->confirmationToken);
                    $event = new ServerSideConfirming($this, $transaction, $confirmationToken);
                    event($event);

                    if (!$event->isValid) {
                        return $this->getResponseModel([
                            'id' => null,
                            'code' => 'payment_rejected',
                            'message' => $event->errorMessage ?? t('Unable to make payment at this time.', category: 'commerce-stripe'),
                        ]);
                    }
                }

                $paymentIntent = $this->createPaymentIntent($transaction, $amount, $metadata, $capture, $form);
            } catch (ApiErrorException $exception) {
                return $this->createPaymentResponseFromError($exception);
            }

            return new ServerConfirmedPaymentIntentResponse($paymentIntent->toArray(), $this->getCompletePaymentUrl($transaction));
        }

        // A payment method ID (from a saved payment source, or a custom payment form) is attached to the payment intent
        // when it’s created, and confirmed straight away.
        $confirmImmediately = false;
        if ($form->paymentMethodId) {
            $confirmImmediately = true;

            // PayPal payment methods can’t be confirmed on the server for on-session use: Stripe requires a
            // `risk_correlation_id` that only PayPal’s client-side SDK can supply during a live browser session. The
            // unconfirmed intent is returned instead, so Stripe.js can confirm it.
            $paymentMethod = $this->getStripeClient()->paymentMethods->retrieve($form->paymentMethodId);
            if ($paymentMethod->type === 'paypal') {
                $confirmImmediately = false;
            }
        }

        $paymentIntent = $this->createPaymentIntent($transaction, $amount, $metadata, $capture, $form);

        if ($confirmImmediately) {
            $paymentIntent = $this->confirmPaymentIntent($paymentIntent, $transaction);
        }

        return new PaymentIntentResponse($paymentIntent->toArray());
    }

    /**
     * Confirms a payment intent.
     *
     * @throws ApiErrorException
     */
    protected function confirmPaymentIntent(PaymentIntent $paymentIntent, Transaction $transaction, ?string $returnUrl = null): PaymentIntent
    {
        // Where Stripe sends the customer after they authenticate or cancel the payment on the payment method’s page.
        // See https://docs.stripe.com/api/payment_intents/confirm#confirm_payment_intent-return_url
        $event = new PaymentIntentConfirming($this, $transaction, [
            'return_url' => $returnUrl ?? $this->getCompletePaymentUrl($transaction),
            'expand' => ['payment_method'],
        ]);
        event($event);

        return $this->getStripeClient()->paymentIntents->confirm($paymentIntent->id, $event->parameters);
    }

    // Payment sources
    // -------------------------------------------------------------------------

    /**
     * @throws PaymentSourceCreatedLaterException when paying with `commerce/payments/pay`, as the payment source is
     * created once the payment succeeds.
     * @throws PaymentSourceException
     */
    #[Override]
    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        $route = request()->route();
        if (!request()->isCpRequest() && $route?->getActionName() === CommercePaymentsController::class . '@pay') {
            throw new PaymentSourceCreatedLaterException(t('The payment source should be created after successful payment.', category: 'commerce-stripe'));
        }

        /** @var PaymentIntentForm $sourceData */
        $lock = Cache::lock("commerceTransaction:{$sourceData->paymentMethodId}", 15);

        try {
            $lock->block(15);

            $client = $this->getStripeClient();
            $stripeCustomer = $this->getStripeCustomer($customerId);
            $paymentMethod = $client->paymentMethods->attach((string)$sourceData->paymentMethodId, ['customer' => $stripeCustomer->id]);

            // Make it the default in Stripe if it’s the customer’s only payment source for this gateway
            if (app(PaymentSources::class)->getAllPaymentSourcesByCustomerId($customerId, $this->id)->isEmpty()) {
                $this->setPaymentSourceAsDefault($stripeCustomer->id, $paymentMethod->id);
            }

            $paymentSource = app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId($paymentMethod->id, $this->id) ?? new PaymentSource();
            $paymentSource->customerId = $customerId;
            $paymentSource->gatewayId = (int)$this->id;
            $paymentSource->token = $paymentMethod->id;
            $paymentSource->response = $paymentMethod->toJSON();
            $paymentSource->description = $this->getPaymentMethodDescription($paymentMethod->toArray()) ?? $paymentMethod->type;

            return $paymentSource;
        } catch (Throwable $exception) {
            throw new PaymentSourceException($exception->getMessage(), previous: $exception);
        } finally {
            $lock->release();
        }
    }

    #[Override]
    public function deletePaymentSource(string $token): bool
    {
        $paymentSources = app(PaymentSources::class);
        $paymentSource = $paymentSources->getPaymentSourceByTokenAndGatewayId($token, $this->id);

        try {
            $this->getStripeClient()->paymentMethods->detach($token);
        } catch (Throwable) {
            // Assume it was already detached.
        }

        $customer = $paymentSource?->getCustomer();

        if ($customer && $paymentSource->getIsPrimary()) {
            // Make the customer’s next payment source the primary one.
            $next = $paymentSources->getAllPaymentSourcesByCustomerId($customer->id, $this->id)
                ->first(fn(PaymentSource $source) => $source->token !== $token);

            if ($next) {
                app(CommerceCustomers::class)->savePrimaryPaymentSourceId($customer, $next->id);
            }
        }

        return true;
    }

    /**
     * Sets a payment method as the default payment method of a Stripe customer.
     *
     * @param string $customer The Stripe customer ID
     * @param string $paymentMethodId The Stripe payment method ID
     */
    public function setPaymentSourceAsDefault(string $customer, string $paymentMethodId): bool
    {
        try {
            $this->getStripeClient()->customers->update($customer, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);

            return true;
        } catch (Exception $exception) {
            Log::error('Unable to set Stripe default payment source: ' . $exception->getMessage());
        }

        return false;
    }

    /**
     * Creates a setup intent, for saving a payment method without paying.
     *
     * @param array<string, mixed> $params
     * @throws ApiErrorException
     */
    public function createSetupIntent(array $params): SetupIntent
    {
        $event = new SetupIntentRequestBuilding($this, Arr::merge([
            'usage' => 'off_session',
            'automatic_payment_methods' => ['enabled' => true],
        ], $params));
        event($event);

        return $this->getStripeClient()->setupIntents->create($event->request);
    }

    /**
     * Returns a URL to the Stripe billing portal for a user.
     *
     * @param string|null $returnUrl The site path or URL to return to. Defaults to the current path.
     * @param string|null $configurationId A Stripe customer portal configuration ID
     * @throws ApiErrorException
     * @throws CustomerException
     */
    public function getBillingPortalUrl(User $user, ?string $returnUrl = null, ?string $configurationId = null): string
    {
        $customer = app(Customers::class)->getCustomer($this->id, $user);

        $params = [
            'customer' => $customer->reference,
            'return_url' => Url::siteUrl($returnUrl ?: request()->path()),
        ];

        if ($configurationId) {
            $params['configuration'] = $configurationId;
        }

        $session = $this->getStripeClient()->billingPortal->sessions->create($params);
        $separator = parse_url($session->url, PHP_URL_QUERY) === null ? '?' : '&';

        return $session->url . $separator . 'customer_id=' . $customer->reference;
    }

    // Webhooks
    // -------------------------------------------------------------------------

    #[Override]
    public function processWebHook(): Response
    {
        $request = request();
        $rawData = $request->getContent();
        $secret = $this->getSigningSecret();
        $signature = (string)$request->header('Stripe-Signature', '');

        if (!$secret || !$signature) {
            Log::warning('Stripe webhook not signed or signing secret not set.');

            return response('Webhook not signed or signing secret not set.', 403);
        }

        try {
            Webhook::constructEvent($rawData, $signature, $secret);
        } catch (Exception $exception) {
            Log::warning('Stripe webhook signature check failed: ' . $exception->getMessage());

            return response('Webhook signature check failed.', 403);
        }

        $data = Json::decodeIfJson($rawData);

        if (is_array($data)) {
            try {
                $this->handleWebhook($data);
            } catch (Throwable $exception) {
                report($exception);
            }

            event(new WebhookReceived($this, $data));
        } else {
            Log::warning('Could not decode the Stripe webhook’s JSON payload.');
        }

        return response('ok');
    }

    #[Override]
    public function getTransactionHashFromWebhook(): ?string
    {
        $data = Json::decodeIfJson(request()->getContent());

        if (!is_array($data)) {
            return null;
        }

        $object = $data['data']['object'] ?? [];

        // Payment intents and Checkout sessions created by the gateway have the transaction hash in their metadata.
        $hash = $object['metadata']['transaction_reference'] ?? null;

        // Other objects are locked by the ID of the Stripe object they belong to, so Commerce can still lock them.
        if (!is_string($hash) || $hash === '') {
            $hash = $object['payment_intent'] ?? $object['id'] ?? null;
        }

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Handles a webhook whose signature has been verified.
     *
     * @param array<string, mixed> $data The Stripe event
     */
    public function handleWebhook(array $data): void
    {
        switch ($data['type'] ?? null) {
            case 'payment_method.attached':
            case 'payment_method.updated':
            case 'payment_method.automatically_updated':
                $this->handlePaymentMethodUpdated($data['data']['object']);
                break;
            case 'payment_method.detached':
                $this->handlePaymentMethodDetached($data);
                break;
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($data);
                break;
            case 'payment_intent.requires_action':
                $this->handlePaymentIntentRequiresAction($data);
                break;
            case 'customer_cash_balance_transaction.created':
                $this->handleCustomerCashBalanceTransaction($data);
                break;
            case 'refund.updated':
                // `refund.updated` is sent for all payment methods, and `charge.refund.updated` only for some.
                // See https://docs.stripe.com/api/events/types#event_types-charge.refund.updated
                $this->handleRefundUpdated($data);
                break;
            case 'customer.updated':
                $this->handleCustomerUpdated($data);
                break;
        }
    }

    /**
     * Marks a transaction as successful when its payment intent (or the Checkout session it belongs to) succeeds.
     *
     * @param array<string, mixed> $data
     */
    public function handlePaymentIntentSucceeded(array $data): void
    {
        $paymentIntent = $data['data']['object'];

        if (($paymentIntent['object'] ?? null) !== PaymentIntent::OBJECT_NAME) {
            return;
        }

        $transactions = app(Transactions::class);

        // A payment intent created by a Checkout session is completed through the session’s transaction.
        $sessions = $this->getStripeClient()->checkout->sessions->all(['payment_intent' => $paymentIntent['id']]);

        if ($session = $sessions->data[0] ?? null) {
            $transaction = $transactions->getTransactionByReference($session->id);

            if (!$transaction?->id) {
                Log::warning('Transaction with the reference “' . $session->id . '” not found when processing webhook ' . $data['id']);

                return;
            }

            $error = '';
            app(Payments::class)->completePayment($transaction, $error);

            return;
        }

        $transaction = $transactions->getTransactionByReference($paymentIntent['id']);

        if (!$transaction?->id) {
            Log::warning('Transaction with the reference “' . $paymentIntent['id'] . '” not found when processing webhook ' . $data['id']);

            return;
        }

        if ($transaction->parentId !== null || $transaction->status === TransactionModel::STATUS_SUCCESS) {
            return;
        }

        $children = $transactions->getChildrenByTransactionId($transaction->id);

        // Skip it if there’s already a successful child, to avoid duplicates.
        foreach ($children as $child) {
            if ($child->status === TransactionModel::STATUS_SUCCESS) {
                return;
            }
        }

        $pending = [TransactionModel::STATUS_PROCESSING, TransactionModel::STATUS_REDIRECT];
        $updateTransaction = null;

        if (empty($children) && in_array($transaction->status, $pending, true)) {
            $updateTransaction = $transaction;
        }

        foreach ($children as $child) {
            if ($child->reference === $transaction->reference && in_array($child->status, $pending, true) && $paymentIntent['status'] === PaymentIntent::STATUS_SUCCEEDED) {
                $updateTransaction = $child;
                break;
            }
        }

        if (!$updateTransaction) {
            return;
        }

        // Commerce doesn’t let transactions be modified through its service, so the row is updated directly.
        $updated = $this->updateTransactionRow($updateTransaction, [
            'status' => TransactionModel::STATUS_SUCCESS,
            'message' => '',
            'response' => $this->expandPaymentMethod($paymentIntent),
        ]);

        if ($updated) {
            // Successful children are consolidated into the updated transaction.
            TransactionModel::query()
                ->where('parentId', $updateTransaction->id)
                ->where('status', TransactionModel::STATUS_SUCCESS)
                ->delete();
        }

        $this->refreshOrderPaidInformation($transaction);
    }

    /**
     * Marks a failed transaction as processing when its payment intent needs an asynchronous action, like ACH
     * micro-deposit verification.
     *
     * @param array<string, mixed> $data
     */
    public function handlePaymentIntentRequiresAction(array $data): void
    {
        $paymentIntent = $data['data']['object'];

        if (($paymentIntent['object'] ?? null) !== PaymentIntent::OBJECT_NAME) {
            return;
        }

        $transaction = app(Transactions::class)->getTransactionByReference($paymentIntent['id']);

        if (!$transaction?->id) {
            Log::warning('Transaction with the reference “' . $paymentIntent['id'] . '” not found when processing webhook ' . $data['id']);

            return;
        }

        // Only failed transactions are updated. Redirect transactions are left alone so the customer returning to
        // `commerce/payments/complete-payment` can still complete the payment and the order.
        if ($transaction->status !== TransactionModel::STATUS_FAILED) {
            return;
        }

        // Only asynchronous next actions count as processing, not actions the customer completes straight away
        // (such as `redirect_to_url` for Klarna or 3D Secure).
        if (!(new PaymentIntentResponse($paymentIntent))->isProcessing()) {
            return;
        }

        $this->updateTransactionRow($transaction, [
            'status' => TransactionModel::STATUS_PROCESSING,
            'message' => '',
            'response' => $this->expandPaymentMethod($paymentIntent),
        ]);
    }

    /**
     * Records a payment funded by the customer’s cash balance, for bank transfers.
     *
     * @param array<string, mixed> $data
     */
    protected function handleCustomerCashBalanceTransaction(array $data): void
    {
        $cashBalance = $data['data']['object'];

        if (($cashBalance['object'] ?? null) !== CustomerCashBalanceTransaction::OBJECT_NAME
            || ($cashBalance['type'] ?? null) !== CustomerCashBalanceTransaction::TYPE_APPLIED_TO_PAYMENT
            || !isset($cashBalance['applied_to_payment']['payment_intent'])
        ) {
            return;
        }

        $transactions = app(Transactions::class);
        $transaction = $transactions->getTransactionByReference($cashBalance['applied_to_payment']['payment_intent']);

        if (!$transaction || $transaction->parentId !== null) {
            return;
        }

        // Find the processing child transaction with the same reference
        $childTransaction = collect($transactions->getChildrenByTransactionId($transaction->id))
            ->first(fn(Transaction $child) => $child->reference === $transaction->reference && $child->status === TransactionModel::STATUS_PROCESSING);

        if (!$childTransaction) {
            return;
        }

        $order = $this->getOrder($childTransaction);
        $newTransaction = $transactions->createTransaction($order, $childTransaction);

        // `net_amount` is negative when the balance is applied to a payment
        $paymentAmount = abs($cashBalance['net_amount']) / (10 ** app(Currencies::class)->getSubunitFor(strtoupper($cashBalance['currency'])));

        $newTransaction->amount = $paymentAmount;
        if ($newTransaction->currency !== $newTransaction->paymentCurrency) {
            $orderCurrency = app(PaymentCurrencies::class)->getPaymentCurrencyByIso((string)$newTransaction->currency, $order->getStore()->id);
            $newTransaction->amount = CurrencyHelper::round($paymentAmount / $newTransaction->paymentRate, $orderCurrency);
        }

        $newTransaction->paymentAmount = $paymentAmount;
        $newTransaction->status = TransactionModel::STATUS_SUCCESS;
        $newTransaction->response = $cashBalance;

        $transactions->saveTransaction($newTransaction, false);
    }

    /**
     * Updates a refund transaction from its refund’s status.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRefundUpdated(array $data): void
    {
        $stripeRefund = $data['data']['object'];
        $transaction = app(Transactions::class)->getTransactionByReference($stripeRefund['id']);

        if (!$transaction) {
            return;
        }

        [$status, $message] = match ($stripeRefund['status'] ?? null) {
            Refund::STATUS_SUCCEEDED => [TransactionModel::STATUS_SUCCESS, ''],
            Refund::STATUS_REQUIRES_ACTION => [TransactionModel::STATUS_PROCESSING, 'Requires action, see next action data in response.'],
            Refund::STATUS_PENDING => [TransactionModel::STATUS_PROCESSING, (string)($stripeRefund['pending_reason'] ?? '')],
            Refund::STATUS_FAILED => [TransactionModel::STATUS_FAILED, (string)($stripeRefund['failure_reason'] ?? '')],
            Refund::STATUS_CANCELED => [TransactionModel::STATUS_FAILED, 'Cancelled'],
            default => [TransactionModel::STATUS_FAILED, $transaction->message],
        };

        $this->updateTransactionRow($transaction, [
            'status' => $status,
            'message' => $message,
            'response' => $data['data'],
        ]);

        $this->refreshOrderPaidInformation($transaction);
    }

    /**
     * Deletes the payment source of a detached payment method.
     *
     * @param array<string, mixed> $data
     */
    protected function handlePaymentMethodDetached(array $data): void
    {
        $paymentSources = app(PaymentSources::class);

        if ($paymentSource = $paymentSources->getPaymentSourceByTokenAndGatewayId($data['data']['object']['id'], $this->id)) {
            $paymentSources->deletePaymentSourceById($paymentSource->id);
        }
    }

    /**
     * Creates or updates the payment source for a Stripe payment method that belongs to a known customer.
     *
     * @param array<string, mixed> $stripePaymentMethod
     * @throws ApiErrorException
     */
    public function handlePaymentMethodUpdated(array $stripePaymentMethod): void
    {
        if (empty($stripePaymentMethod['customer'])) {
            return;
        }

        $customer = app(Customers::class)->getCustomerByReference($stripePaymentMethod['customer']);

        // We don’t know who this customer is.
        if (!$customer) {
            return;
        }

        $user = Users::getUserById($customer->userId);

        if (!$user) {
            return;
        }

        // Make sure the customer exists in Stripe
        $stripeCustomer = $this->getStripeClient()->customers->retrieve($stripePaymentMethod['customer']);

        $paymentSources = app(PaymentSources::class);
        $paymentSource = $paymentSources->getPaymentSourceByTokenAndGatewayId($stripePaymentMethod['id'], $this->id) ?? new PaymentSource();
        $paymentSource->gatewayId = (int)$this->id;
        $paymentSource->token = $stripePaymentMethod['id'];
        $paymentSource->customerId = $user->id;
        $paymentSource->response = (string)Json::encode($stripePaymentMethod);

        if (!$paymentSource->id || empty($paymentSource->description)) {
            $paymentSource->description = $this->getPaymentMethodDescription($stripePaymentMethod) ?? 'Stripe payment source';
        }

        // No harm in making sure it’s attached to the customer.
        $this->getStripeClient()->paymentMethods->attach($stripePaymentMethod['id'], ['customer' => $stripeCustomer->id]);

        if (!$paymentSources->savePaymentSource($paymentSource)) {
            Log::error('Could not save payment source: ' . Json::encode($paymentSource->errors()->toArray()));

            return;
        }

        /** @phpstan-ignore-next-line method.notFound (getPrimaryPaymentSourceId() is a macro Commerce adds to users) */
        if (!$user->getPrimaryPaymentSourceId()) {
            app(CommerceCustomers::class)->savePrimaryPaymentSourceId($user, $paymentSource->id);
            $this->setPaymentSourceAsDefault($stripeCustomer->id, $stripePaymentMethod['id']);
        }
    }

    /**
     * Makes the customer’s default payment method in Stripe their primary payment source in Commerce.
     *
     * @param array<string, mixed> $data
     */
    public function handleCustomerUpdated(array $data): void
    {
        $stripeCustomer = $data['data']['object'];
        $defaultPaymentMethod = $stripeCustomer['invoice_settings']['default_payment_method'] ?? $stripeCustomer['default_source'] ?? null;

        if (!$defaultPaymentMethod) {
            return;
        }

        $customer = app(Customers::class)->getCustomerByReference($stripeCustomer['id'], $this->id);
        $user = $customer?->getUser();

        if (!$user) {
            return;
        }

        $paymentSource = app(PaymentSources::class)->getPaymentSourceByTokenAndGatewayId($defaultPaymentMethod, $this->id);

        if ($paymentSource) {
            app(CommerceCustomers::class)->savePrimaryPaymentSourceId($user, $paymentSource->id);
        }
    }

    // Supports
    // -------------------------------------------------------------------------

    #[Override]
    public function supportsAuthorize(): bool
    {
        return true;
    }

    #[Override]
    public function supportsCapture(): bool
    {
        return true;
    }

    #[Override]
    public function supportsCompleteAuthorize(): bool
    {
        return true;
    }

    #[Override]
    public function supportsCompletePurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPaymentSources(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPartialRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsWebhooks(): bool
    {
        return true;
    }

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the Stripe customer for a user, creating it if needed.
     *
     * @throws CustomerException if the customer can’t be created or retrieved
     */
    protected function getStripeCustomer(int $userId): StripeCustomer
    {
        try {
            $user = Users::getUserById($userId) ?? throw new RuntimeException("User $userId not found.");
            $customers = app(Customers::class);
            $customer = $customers->getCustomer($this->id, $user);
            $stripeCustomer = $this->getStripeClient()->customers->retrieve($customer->reference);

            if (!empty($stripeCustomer->deleted)) {
                // The customer was deleted in Stripe, so create a new one, once.
                $customers->deleteCustomerById($customer->id);
                $customer = $customers->getCustomer($this->id, $user);
                $stripeCustomer = $this->getStripeClient()->customers->retrieve($customer->reference);
            }

            return $stripeCustomer;
        } catch (Exception $exception) {
            throw new CustomerException('Could not fetch Stripe customer: ' . $exception->getMessage(), previous: $exception);
        }
    }

    protected function createPaymentResponseFromApiResource(ApiResource $resource): RequestResponseInterface
    {
        return $this->getResponseModel($resource->toArray());
    }

    /**
     * Creates a response from a Stripe API error.
     *
     * @throws Exception if it isn’t a Stripe API error
     */
    protected function createPaymentResponseFromError(Exception $exception): RequestResponseInterface
    {
        if ($exception instanceof CardException) {
            $body = $exception->getJsonBody() ?? [];
            $data = $body;
            $data['code'] = $body['error']['code'] ?? $exception->getStripeCode();
            $data['message'] = $body['error']['message'] ?? $exception->getMessage();
            if (isset($body['error']['charge'])) {
                $data['id'] = $body['error']['charge'];
            }
        } elseif ($exception instanceof ApiErrorException) {
            // Not a declined card, but something else
            $body = $exception->getJsonBody() ?? [];
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
     * Converts an amount to the currency’s minor unit, which is what Stripe expects.
     */
    protected function toMinorUnits(float $amount, string $currency): int
    {
        return (int)app(Currencies::class)->getTeller($currency)->convertToMoney($amount)->getAmount();
    }

    /**
     * Returns the URL that completes the payment for a transaction.
     */
    protected function getCompletePaymentUrl(Transaction $transaction): string
    {
        return Url::actionUrl('commerce/payments/complete-payment', [
            'commerceTransactionId' => $transaction->id,
            'commerceTransactionHash' => $transaction->hash,
        ]);
    }

    /**
     * Returns the order’s billing details in the format Stripe.js expects.
     *
     * @return array<string, mixed>
     */
    protected function getBillingDetails(Order $order): array
    {
        $billingAddress = $order->getBillingAddress();

        return [
            'name' => $billingAddress->fullName ?? '',
            'email' => $order->getEmail(),
            'address' => [
                'country' => $billingAddress?->getCountryCode() ?: '',
                'line1' => $billingAddress->addressLine1 ?? '',
                'line2' => $billingAddress->addressLine2 ?? '',
                'city' => $billingAddress->locality ?? '',
                'postal_code' => $billingAddress->postalCode ?? '',
                'state' => $billingAddress?->getAdministrativeArea() ?? '',
            ],
        ];
    }

    /**
     * Returns the options for creating Stripe Elements without a payment intent, so the payment intent can be created
     * and confirmed on the server when the customer submits the payment form.
     *
     * The amount is only used by Stripe Elements to show the available payment methods. The amount charged is
     * calculated on the server when the payment intent is created.
     *
     * @return array<string, mixed>
     * @see https://docs.stripe.com/payments/finalize-payments-on-the-server
     */
    protected function getDeferredElementsOptions(Order $order): array
    {
        $paymentCurrency = $order->getPaymentCurrency();

        return [
            'mode' => 'payment',
            'amount' => $this->toMinorUnits($order->getPaymentAmount(), $paymentCurrency),
            'currency' => strtolower($paymentCurrency),
            'captureMethod' => $this->paymentType === TransactionModel::TYPE_AUTHORIZE ? 'manual' : 'automatic',
            'paymentMethodCreation' => 'manual',
        ];
    }

    /**
     * Returns a description of a payment method, like “Visa ending in ••••4242”.
     *
     * @param array<string, mixed> $paymentMethod
     */
    protected function getPaymentMethodDescription(array $paymentMethod): ?string
    {
        $type = $paymentMethod['type'] ?? null;

        if ($type === 'card') {
            return t('{cardType} ending in ••••{last4}', [
                'cardType' => Str::ucfirst((string)($paymentMethod['card']['brand'] ?: 'Card')),
                'last4' => $paymentMethod['card']['last4'] ?? '',
            ], category: 'commerce-stripe');
        }

        if ($type && isset($paymentMethod[$type]['last4'])) {
            return t('Payment method ending in ••••{last4}', ['last4' => $paymentMethod[$type]['last4']], category: 'commerce-stripe');
        }

        return null;
    }

    /**
     * Expands the payment method on a payment intent from a webhook payload, which only has the payment method ID.
     *
     * This keeps the stored transaction response the same as the responses stored when paying, which expand the
     * payment method so its details (such as the card brand and last 4 digits) are available.
     *
     * @param array<string, mixed> $paymentIntent
     * @return array<string, mixed>
     */
    protected function expandPaymentMethod(array $paymentIntent): array
    {
        $paymentMethodId = $paymentIntent['payment_method'] ?? null;

        if (!is_string($paymentMethodId) || $paymentMethodId === '') {
            return $paymentIntent;
        }

        try {
            $paymentIntent['payment_method'] = $this->getStripeClient()->paymentMethods->retrieve($paymentMethodId)->toArray();
        } catch (Throwable $exception) {
            Log::warning('Unable to retrieve payment method “' . $paymentMethodId . '”: ' . $exception->getMessage());
        }

        return $paymentIntent;
    }

    /**
     * Updates a saved transaction’s row. Commerce doesn’t let transactions be modified through its service.
     *
     * @param array{status: string, message: ?string, response: mixed} $attributes
     */
    protected function updateTransactionRow(Transaction $transaction, array $attributes): bool
    {
        $updated = TransactionModel::query()->whereKey($transaction->id)->update([
            'status' => $attributes['status'],
            'message' => $attributes['message'],
            'response' => is_scalar($attributes['response']) ? $attributes['response'] : Json::encode($attributes['response']),
        ]);

        if (!$updated) {
            Log::warning('Transaction record not found for ID: ' . $transaction->id);

            return false;
        }

        return true;
    }

    /**
     * Updates the paid status of the transaction’s order, after its transactions were changed.
     */
    protected function refreshOrderPaidInformation(Transaction $transaction): void
    {
        $order = $transaction->getOrder();
        $order?->setTransactions(null);
        $order?->updateOrderPaidInformation();
    }

    protected function getOrder(Transaction $transaction): Order
    {
        return $transaction->getOrder() ?? throw new RuntimeException('The transaction has no order.');
    }
}
