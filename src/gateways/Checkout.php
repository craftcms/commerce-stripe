<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\models\forms\payment\Checkout as CheckoutForm;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\CheckoutSessionResponse;
use craft\commerce\stripe\responses\PaymentIntentResponse;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\StripeClient;

/**
 * This class represents the Stripe Checkout gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 **/
class Checkout extends PaymentIntents
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce-stripe', 'Stripe Checkout');
    }

    /**
     * @return StripeClient
     */
    public function getStripeClient(): StripeClient
    {
        Stripe::setAppInfo(StripePlugin::getInstance()->name, StripePlugin::getInstance()->version, StripePlugin::getInstance()->documentationUrl);
        Stripe::setApiKey($this->getApiKey());
        Stripe::setApiVersion(self::STRIPE_API_VERSION);

        return new StripeClient([
            'api_key' => $this->getApiKey()
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params): ?string
    {
        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerScript('', View::POS_END, ['src' => 'https://js.stripe.com/v3/']); // we need this to load at end of body

        $html = '';
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new CheckoutForm();
    }

    /**
     * @inheritdoc
     */
    protected function authorizeOrPurchase(Transaction $transaction, BasePaymentForm $form, bool $capture = true): RequestResponseInterface
    {
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($transaction->paymentCurrency);

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
                    'name' => Craft::$app->getSites()->getCurrentSite()->name. ' Order #'.$transaction->getOrder()->shortNumber,
                    'metadata' => [
                        'order_id' => $transaction->getOrder()->id,
                        'order_number' => $transaction->getOrder()->number,
                        'order_short_number' => $transaction->getOrder()->shortNumber,
                    ]
                ]
            ],
            'adjustable_quantity' => [
                'enabled' => false
            ],
            'quantity' => 1,
        ];

        $paymentIntentData = [
            'setup_future_usage' => 'on_session'
        ];

        $data = [
            'cancel_url' => $transaction->getOrder()->cancelUrl,
            'success_url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash]),
            'mode' => 'payment',
            'client_reference_id' => $transaction->hash,
            'customer' => StripePlugin::getInstance()->getCustomers()->getCustomer($this->id, $transaction->getOrder()->getCustomer())->reference,
            'line_items' => $lineItems,
            'metadata' => $metadata,
            'allow_promotion_codes' => false,
            'payment_intent_data' => $paymentIntentData,
        ];

        $session = $this->getStripeClient()->checkout->sessions->create($data);
        return new CheckoutSessionResponse($session->toArray());
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $data = Json::decodeIfJson($transaction->response);
        $session = $this->getStripeClient()->checkout->sessions->retrieve($data['id']);
        $paymentIntent = $this->getStripeClient()->paymentIntents->retrieve($data['payment_intent']);

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
