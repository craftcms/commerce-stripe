<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\base\Model;
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
use craft\commerce\stripe\errors\PaymentSourceException;
use craft\commerce\stripe\events\SubscriptionRequestEvent;
use craft\commerce\stripe\models\forms\payment\PaymentIntent as PaymentForm;
use craft\commerce\stripe\models\forms\Subscription as SubscriptionForm;
use craft\commerce\stripe\models\PaymentIntent as PaymentIntentModel;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\PaymentIntentResponse;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\View;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\Subscription as StripeSubscription;
use yii\base\NotSupportedException;

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
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params)
    {
        $this->configureStripeClient();

        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerScript('', View::POS_END, ['src' => 'https://js.stripe.com/v3/']); // we need this to load at end of body

        // Not needed at the moment
        // $view->registerAssetBundle(IntentsFormAsset::class);

        $params = array_merge([],$params);
        $html = $view->renderTemplate('commerce-stripe/paymentForms/checkoutForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        $this->configureStripeClient();
        return new Model(); // nothing specific to checkout in a model at the moment
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $this->configureStripeClient();
        $paymentIntentReference = Craft::$app->getRequest()->getParam('payment_intent');
        /** @var PaymentIntent $paymentIntent */
        $stripePaymentIntent = PaymentIntent::retrieve($paymentIntentReference);

        // Update the intent with the latest.
        $paymentIntentsService = StripePlugin::getInstance()->getPaymentIntents();

        $paymentIntent = $paymentIntentsService->getPaymentIntentByReference($paymentIntentReference);

        // Make sure we have the payment intent before we attempt to do anything with it.
        if ($paymentIntent) {
            $paymentIntent->intentData = $stripePaymentIntent->jsonSerialize();
            $paymentIntentsService->savePaymentIntent($paymentIntent);
        }

        $intentData = $stripePaymentIntent->jsonSerialize();

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

        try {
            // If this is a customer that's logged in, attempt to continue the timeline
            if ($customer) {
                $paymentIntentService = $stripePlugin->getPaymentIntents();
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
                $paymentIntent->intentData = $stripePaymentIntent->jsonSerialize();
                $paymentIntentService->savePaymentIntent($paymentIntent);
            }

            $this->_confirmPaymentIntent($stripePaymentIntent, $transaction);

            return $this->createPaymentResponsocartconeFromApiResource($stripePaymentIntent);
        } catch (\Exception $exception) {
            return $this->createPaymentResponseFromError($exception);
        }
    }

    /**
     * Refresh a subscription's data.
     *
     * @param $subscription
     * @return Subscription
     */
    protected function refreshSubscriptionData(Subscription $subscription)
    {
        $this->configureStripeClient();
        $stripeSubscription = StripeSubscription::retrieve([
            'id' => $subscription->reference,
            'expand' => ['latest_invoice.payment_intent']
        ]);

        $subscription->setSubscriptionData($stripeSubscription->jsonSerialize());
        $this->setSubscriptionStatusData($subscription);
        Craft::$app->getElements()->saveElement($subscription);

        return $subscription;
    }

    // Private methods
    // =========================================================================

    /**
     * Confirm a payment intent and set the return URL.
     *
     * @param PaymentIntent $stripePaymentIntent
     */
    private function _confirmPaymentIntent(PaymentIntent $stripePaymentIntent, Transaction $transaction)
    {
        $this->configureStripeClient();
        $stripePaymentIntent->confirm([
            'return_url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash])
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
                'expand' => ['latest_invoice.payment_intent']
            ]);
            $subscriptionData = $stripeSubscription->jsonSerialize();
            $subscription->setSubscriptionData($subscriptionData);
        }

        return $subscriptionData;
    }
}
