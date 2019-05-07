<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\base\SubscriptionGateway as BaseGateway;
use craft\commerce\stripe\errors\PaymentSourceException as CommercePaymentSourceException;
use craft\commerce\stripe\models\forms\payment\PaymentIntent as PaymentForm;
use craft\commerce\stripe\models\PaymentIntent as PaymentIntentModel;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\PaymentIntentResponse;
use craft\commerce\stripe\web\assets\intentsform\IntentsFormAsset;
use craft\helpers\UrlHelper;
use craft\web\View;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use yii\base\NotSupportedException;

/**
 * This class represents the Stripe Payment Intents gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 **/
class PaymentIntents extends BaseGateway
{
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
     * @var string
     */
    public $signingSecret;

    // Public methods
    // =========================================================================

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
        $view->registerAssetBundle(IntentsFormAsset::class);

        $html = $view->renderTemplate('commerce-stripe/paymentForms/intentsForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
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
        return new PaymentForm();
    }

    /**
     * @inheritdoc
     */
    public function getResponseModel($data): RequestResponseInterface
    {
        return new PaymentIntentResponse($data);
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $paymentIntentReference = Craft::$app->getRequest()->getParam('payment_intent');
        /** @var PaymentIntent $paymentIntent */
        $stripePaymentIntent = PaymentIntent::retrieve($paymentIntentReference);

        // Update the intent with the latest.
        $paymentIntentsService = StripePlugin::getInstance()->getPaymentIntents();

        $paymentIntent = $paymentIntentsService->getPaymentIntentByReference($paymentIntentReference);
        $paymentIntent->intentData = $stripePaymentIntent->jsonSerialize();
        $paymentIntentsService->savePaymentIntent($paymentIntent);

        $intentData = $stripePaymentIntent->jsonSerialize();

        if (!empty($intentData['payment_method'])) {
            $this->_confirmPaymentIntent($stripePaymentIntent, $transaction);
        }

        return $this->createPaymentResponseFromApiResource($stripePaymentIntent);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
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

        $stripePaymentIntent = PaymentIntent::retrieve($transaction->reference);
        $paymentIntentsService = StripePlugin::getInstance()->getPaymentIntents();

        $paymentIntent = $paymentIntentsService->getPaymentIntentByReference($transaction->reference);

        try {
            $refund = Refund::create([
                'charge' => $stripePaymentIntent->charges->data[0]->id,
                'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
            ]);

            // Fetch the new intent data
            $stripePaymentIntent = PaymentIntent::retrieve($transaction->reference);
            $paymentIntent->intentData = $stripePaymentIntent->jsonSerialize();
            $paymentIntentsService->savePaymentIntent($paymentIntent);

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
        /** @var PaymentForm $sourceData */

        try {
            $stripeCustomer = $this->getStripeCustomer($userId);
            $paymentMethod = PaymentMethod::retrieve($sourceData->paymentMethodId);
            $stripeResponse = $paymentMethod->attach(['customer' => $stripeCustomer->id]);

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

    // Protected methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function authorizeOrPurchase(Transaction $transaction, BasePaymentForm $form, bool $capture = true): RequestResponseInterface
    {
        /** @var PaymentForm $form */
        $requestData = $this->buildRequestData($transaction);
        $paymentMethodId = $form->paymentMethodId;

        $stripePlugin = StripePlugin::getInstance();
        if ($form->customer) {
            $requestData['customer'] = $form->customer;
            $customer = $stripePlugin->getCustomers()->getCustomerByReference($form->customer);
        } else if ($user = $transaction->getOrder()->getUser()) {
            $customer = $stripePlugin->getCustomers()->getCustomer($this->id, $user);
            $requestData['customer'] = $customer->reference;
        }

        $requestData['payment_method'] = $paymentMethodId;
        try {
            $paymentIntentService = $stripePlugin->getPaymentIntents();
            $paymentIntent = $paymentIntentService->getPaymentIntent($this->id, $transaction->orderId, $customer->id);

            if ($paymentIntent) {
                $stripePaymentIntent = PaymentIntent::update($paymentIntent->reference, $requestData, ['idempotency_key' => $transaction->hash]);
            } else {
                $requestData['capture_method'] = $capture ? 'automatic' : 'manual';
                $requestData['confirmation_method'] = 'manual';
                $requestData['confirm'] = false;

                $stripePaymentIntent = PaymentIntent::create($requestData, ['idempotency_key' => $transaction->hash]);

                $paymentIntent = new PaymentIntentModel([
                    'orderId' => $transaction->orderId,
                    'customerId' => $customer->id,
                    'gatewayId' => $this->id,
                    'reference' => $stripePaymentIntent->id,
                ]);
            }

            // Save data before confirming.
            $paymentIntent->intentData = $stripePaymentIntent->jsonSerialize();
            $paymentIntentService->savePaymentIntent($paymentIntent);

            $this->_confirmPaymentIntent($stripePaymentIntent, $transaction);

            return $this->createPaymentResponseFromApiResource($stripePaymentIntent);
        } catch (\Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }
    }

    /**
     * Confirm a payment intent and set the return URL.
     *
     * @param PaymentIntent $stripePaymentIntent
     */
    private function _confirmPaymentIntent(PaymentIntent $stripePaymentIntent, Transaction $transaction)
    {
        $stripePaymentIntent->confirm([
            'return_url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash])
        ]);
    }
}
