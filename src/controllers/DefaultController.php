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
use craft\commerce\stripe\base\SubscriptionGateway;
use craft\commerce\stripe\gateways\PaymentIntentsElementsCheckout;
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
class DefaultController extends BaseController
{
    /**
     * Load Stripe Subscription plans for a gateway.
     *
     * @return Response
     */
    public function actionFetchPlans(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $gatewayId = $request->getRequiredBodyParam('gatewayId');

        if (!$gatewayId) {
            return $this->asJson([]);
        }

        try {
            $gateway = CommercePlugin::getInstance()->getGateways()->getGatewayById((int)$gatewayId);

            if (!$gateway instanceof SubscriptionGateway) {
                throw new BadRequestHttpException('That is not a valid gateway id.');
            }

            return $this->asJson($gateway->getSubscriptionPlans());
        } catch (Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionBillingPortal(): Response
    {
        $this->requirePostRequest();

        $redirect = $this->getPostedRedirectUrl() ?? Craft::$app->getRequest()->pathInfo;

        if ($currentUser = Craft::$app->getUser()->getIdentity()) {
            $gatewayHandle = Craft::$app->getRequest()->getRequiredParam('gatewayId');
            if ($gateway = Plugin::getInstance()->getGateways()->getGatewayByHandle($gatewayHandle)) {
                if ($gateway instanceof PaymentIntentsElementsCheckout) {
                    $customer = StripePlugin::getInstance()->getCustomers()->getCustomer($gateway->id, $currentUser);

                    $portal = $gateway->getStripeClient()->billingPortal->sessions->create([
                        'customer' => $customer->reference,
                        'return_url' => UrlHelper::siteUrl($redirect),
                    ]);

                    return $this->redirect($portal->url);
                }
            }
        }


        return $this->asFailure('Can not create billing portal link.');
    }

    public function actionSyncPaymentSources(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $gatewayId = $request->getRequiredBodyParam('gatewayId');

        if (!$gatewayId) {
            return $this->asFailure(Craft::t('commerce-stripe', 'No valid gateway ID provided.'));
        }

        try {
            if ($gateway = CommercePlugin::getInstance()->getGateways()->getGatewayById((int)$gatewayId)) {
                $count = StripePlugin::getInstance()->getPaymentMethods()->syncAllPaymentMethods($gateway);
                return $this->asSuccess(Craft::t('commerce-stripe', 'Synced {count} payment sources.', ['count' => $count]));
            } else {
                return $this->asFailure(Craft::t('commerce-stripe', 'No valid gateway ID provided.'));
            }
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }
}
