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
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\helpers\UrlHelper;
use craft\web\Controller as BaseController;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * This controller provides functionality to load data from AWS.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
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
                $customer = StripePlugin::getInstance()->getCustomers()->getCustomer($gateway->id, $user);

                $portal = $gateway->getStripeClient()->billingPortal->sessions->create([
                    'customer' => $customer->reference,
                    'return_url' => UrlHelper::siteUrl($redirect),
                ]);

                if($this->request->getAcceptsJson())
                {
                    return $this->asJson(['redirect' => $portal->url]);
                }

                return $this->redirect($portal->url);
            }
        }

        return $this->asFailure('Can not create billing portal link.');
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
