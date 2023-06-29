<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\controllers;

use Craft;
use craft\commerce\Plugin;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\commerce\stripe\models\forms\payment\PaymentIntent;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\helpers\UrlHelper;
use craft\web\Controller as BaseController;
use Stripe\StripeClient;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * This controller provides functionality to manage Stripe customer related objects like billing sessions and setup intents.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 */
class CustomersController extends BaseController
{
    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionBillingPortalRedirect(): Response
    {
        $this->requirePostRequest();

        $redirect = $this->getPostedRedirectUrl() ?? Craft::$app->getRequest()->pathInfo;

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return $this->asFailure(Craft::t('commerce-stripe', 'No user logged in.'));
        }

        $gatewayHandle = Craft::$app->getRequest()->getRequiredParam('gatewayId');
        if ($gateway = CommercePlugin::getInstance()->getGateways()->getGatewayByHandle($gatewayHandle)) {
            if ($gateway instanceof PaymentIntents) {
                $url = $gateway->getBillingPortalUrl($user, $redirect);

                if ($this->request->getAcceptsJson()) {
                    return $this->asJson(['redirect' => $url]);
                }

                return $this->redirect($url);
            }
        }

        return $this->asFailure('Can not create billing portal link.');
    }

    public function actionConfirmSetupIntent(): Response
    {
        $params = Craft::$app->getRequest()->getQueryParams();
        /** @var PaymentIntents $gateway */
        $gateway = CommercePlugin::getInstance()->getGateways()->getGatewayById((int)$params['gatewayId']);
        $setupIntent = $gateway->getStripeClient()->setupIntents->retrieve($params['setup_intent']);

        switch ($setupIntent->status) {
            case 'succeeded':
                $message = Craft::$app->getSecurity()->validateData($params['successMessage']);
                $paymentForm = new PaymentIntent();
                $paymentForm->paymentMethodId = $setupIntent->payment_method;
                $description = $params['description'] ?? null;
                $isPrimaryPaymentSource = $params['isPrimaryPaymentSource'] ?? false;
                $customer = StripePlugin::getInstance()->getCustomers()->getCustomerByReference($setupIntent->customer, $gateway->id);

                if ($customer) {
                    $paymentSource = Plugin::getInstance()->getPaymentSources()->createPaymentSource($customer->getUser()->id, $gateway, $paymentForm, $description, $isPrimaryPaymentSource);
                }

                break;

            case 'processing':
                $message = "Processing payment details. We'll update you when processing is complete.";
                break;

            case 'requires_payment_method':
                $message = 'Failed to process payment details. Please try another payment method.';

                $cancelUrl = Craft::$app->getSecurity()->validateData($params['cancelUrl']);

                return $this->redirect($cancelUrl);

                break;
        }

        $redirectUrl = Craft::$app->getSecurity()->validateData($params['redirect']);

        return $this->redirect($redirectUrl);
    }

    public function actionCreateSetupIntent(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $request = Craft::$app->getRequest();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return $this->asFailure(Craft::t('commerce-stripe', 'No user logged in.'));
        }

        $gatewayId = $request->getRequiredBodyParam('gatewayId');

        if (!$gatewayId) {
            return $this->asFailure(Craft::t('commerce-stripe', 'Missing gateway ID.'));
        }

        $customer = StripePlugin::getInstance()->getCustomers()->getCustomer($gatewayId, $user);

        try {
            $gateway = CommercePlugin::getInstance()->getGateways()->getGatewayById((int)$gatewayId);
            $setupIntent = [
                'customer' => $customer->reference,
                'payment_method_types' => ['bancontact', 'card', 'ideal'],
            ];
            return $this->asJson($gateway->createSetupIntent($setupIntent));
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }
}