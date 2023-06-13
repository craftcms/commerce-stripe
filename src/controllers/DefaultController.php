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
class DefaultController extends BaseController
{
    /**
     * Load Stripe Subscription plans for a gateway.
     *
     * @return Response
     *
     * @deprecated in 4.0. Use [[\craft\commerce\base\SubscriptionGatewayInterface::getSubscriptionPlans()]] instead.
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

            return $this->asJson($gateway->getSubscriptionPlans());
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionSyncPaymentSources(): Response
    {
        $this->requireAdmin(false);
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
