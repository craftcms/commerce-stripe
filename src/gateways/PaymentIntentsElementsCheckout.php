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
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\models\forms\payment\ElementsCheckout as ElementsCheckoutForm;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\CheckoutSessionResponse;
use craft\commerce\stripe\responses\PaymentIntentResponse;
use craft\commerce\stripe\web\assets\elementsform\ElementsFormAsset;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;

/**
 * This class represents the Stripe Checkout gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 **/
class PaymentIntentsElementsCheckout extends PaymentIntents
{
    public const PAYMENT_FORM_TYPE_CHECKOUT = 'checkout';
    public const PAYMENT_FORM_TYPE_ELEMENTS = 'elements';

    /**
     * @var string
     */
    public string $_formType = 'elements';

    /**
     * @return string
     */
    public function getFormType(): string
    {
        return $this->_formType;
    }

    /**
     * @inheritDoc
     */
    public function showPaymentFormSubmitButton(): bool
    {
        return $this->getFormType() == self::PAYMENT_FORM_TYPE_CHECKOUT;
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
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-stripe/gatewaySettings/intentsCheckoutSettings', ['gateway' => $this]);
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
    public function getPaymentFormHtml(array $params): ?string
    {
        $defaults = [
            'gateway' => $this,
            'handle' => $this->handle,
            'appearance' => [],
            'layout' => [],
            'paymentFormType' => $this->getFormType(),
        ];

        $params = array_merge($defaults, $params);

        // Set it in memory on the gateway for this request
        $this->setFormType($params['paymentFormType']);

        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerScript('', View::POS_END, ['src' => 'https://js.stripe.com/v3/']); // we need this to load at end of body

        if ($this->getFormType() == self::PAYMENT_FORM_TYPE_CHECKOUT) {
            $html = $view->renderTemplate('commerce-stripe/paymentForms/checkoutForm', $params);
            ;
        } else {
            $view->registerAssetBundle(ElementsFormAsset::class);
            $html = $view->renderTemplate('commerce-stripe/paymentForms/elementsForm', $params);
        }

        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new ElementsCheckoutForm();
    }

    /**
     * @inheritdoc
     */
    protected function authorizeOrPurchase(Transaction $transaction, ElementsCheckoutForm|BasePaymentForm $form, bool $capture = true): RequestResponseInterface
    {
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($transaction->paymentCurrency);

        /** @var ElementsCheckoutForm $form */
        if ($form->paymentFormType == self::PAYMENT_FORM_TYPE_CHECKOUT) {
            $metadata = [
                'order_id' => $transaction->getOrder()->id,
                'order_number' => $transaction->getOrder()->number,
                'transaction_id' => $transaction->id,
                'transaction_reference' => $transaction->hash,
            ];
            $lineItems = [];
            $lineItems[] = [
                'price_data' => [
                    'currency' => $transaction->paymentCurrency,
                    'unit_amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
                    'tax_behavior' => 'inclusive',
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
                'setup_future_usage' => 'on_session',
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

            $user = Craft::$app->getUser()->getIdentity();
            $orderCustomer = $transaction->getOrder()->getCustomer();
            if ($user && $orderCustomer && $user->id == $orderCustomer->id) {
                $data['customer'] = StripePlugin::getInstance()->getCustomers()->getCustomer($this->id, $transaction->getOrder()->getCustomer())->reference;
            } else {
                $data['customer_email'] = $transaction->getOrder()->getEmail();
            }

            $session = $this->getStripeClient()->checkout->sessions->create($data);
            return new CheckoutSessionResponse($session->toArray());
        }

        $paymentIntent = $this->getStripeClient()->paymentIntents->create(
            [
                'amount' => $transaction->paymentAmount * (10 ** $currency->minorUnit),
                'currency' => $transaction->paymentCurrency,
                'automatic_payment_methods' => ['enabled' => true],
                'receipt_email' => $transaction->getOrder()->getEmail(),
            ]
        );

        return new PaymentIntentResponse($paymentIntent->toArray());
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

        return new PaymentIntentResponse($paymentIntent->toArray());
    }

    /**
     * @return bool
     */
    public function supportsPaymentSources(): bool
    {
        return false;
    }
}
