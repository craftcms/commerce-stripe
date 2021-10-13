<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\base\SubscriptionGateway;
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
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->defaultAction = 'fetch-plans';
    }

    /**
     * Load Stripe Subscription plans for a gateway.
     *
     * @return Response
     */
    public function actionFetchPlans()
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
}
