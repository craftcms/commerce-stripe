<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\controllers;

use Craft;
use craft\commerce\Plugin;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\base\SubscriptionGateway;
use craft\commerce\stripe\gateways\Checkout;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\helpers\UrlHelper;
use craft\web\Controller as BaseController;
use Throwable;
use yii\helpers\Url;
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
            $gateway = Commerce::getInstance()->getGateways()->getGatewayById((int)$gatewayId);

            if (!$gateway instanceof SubscriptionGateway) {
                throw new BadRequestHttpException('That is not a valid gateway id.');
            }

            return $this->asJson($gateway->getSubscriptionPlans());
        } catch (Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }

    /**
     * @return void
     * @throws BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionBillingPortal(): Response
    {
        $this->requirePostRequest();
        $redirect = $this->getPostedRedirectUrl() ?? Craft::$app->getRequest()->pathInfo;

        if ($currentUser = Craft::$app->getUser()->getIdentity()) {
            $gatewayHandle = Craft::$app->getRequest()->getRequiredParam('gatewayHandle');
            if ($gateway = Plugin::getInstance()->getGateways()->getGatewayByHandle($gatewayHandle)) {
                if ($gateway instanceof Checkout) {
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

}
