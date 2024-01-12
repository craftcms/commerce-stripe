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
use craft\web\Controller as BaseController;
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

    /**
     * @return Response
     * @throws \Stripe\Exception\ApiErrorException
     * @throws \craft\commerce\errors\PaymentSourceException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function actionConfirmSetupIntent(): Response
    {
        $params = Craft::$app->getRequest()->getQueryParams();
        /** @var PaymentIntents $gateway */
        $gateway = CommercePlugin::getInstance()->getGateways()->getGatewayById((int)$params['gatewayId']);
        $setupIntent = $gateway->getStripeClient()->setupIntents->retrieve($params['setup_intent']);

        switch ($setupIntent->status) {
            case 'succeeded':
            case 'processing':
                $message = Craft::$app->getSecurity()->validateData($params['successMessage']);
                $paymentForm = new PaymentIntent();
                $paymentForm->paymentMethodId = $setupIntent->payment_method;
                $description = $params['description'] ?? null;
                $isPrimaryPaymentSource = $params['isPrimaryPaymentSource'] ?? false;
                $customer = StripePlugin::getInstance()->getCustomers()->getCustomerByReference($setupIntent->customer, $gateway->id);

                if ($customer) {
                    Plugin::getInstance()->getPaymentSources()->createPaymentSource($customer->getUser()->id, $gateway, $paymentForm, $description, $isPrimaryPaymentSource);
                }

                break;
            case 'requires_payment_method':
                $cancelUrl = Craft::$app->getSecurity()->validateData($params['cancelUrl']);
                $this->setFailFlash(Craft::t('commerce-stripe', 'Failed to process payment details. Please try another payment method.'));
                return $this->redirect($cancelUrl);
        }

        $redirectUrl = Craft::$app->getSecurity()->validateData($params['redirect']);
        $this->setSuccessFlash($message ?? Craft::t('commerce-stripe', 'Payment source saved.'));
        return $this->redirect($redirectUrl);
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws Throwable
     * @throws \craft\commerce\stripe\errors\CustomerException
     */
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
            /** @var PaymentIntents $gateway */
            $gateway = CommercePlugin::getInstance()->getGateways()->getGatewayById((int)$gatewayId);
            $setupIntent = [
                'customer' => $customer->reference,
            ];
            return $this->asJson($gateway->createSetupIntent($setupIntent));
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }
}
