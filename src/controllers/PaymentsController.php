<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\controllers;

use craft\commerce\Plugin;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\web\Controller as BaseController;
use yii\web\Response;

/**
 * This controller provides functionality to...
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 */
class PaymentsController extends BaseController
{
    protected array|bool|int $allowAnonymous = ['save-payment-intent'];

    public function actionSavePaymentIntent(): Response
    {
        $this->requireAcceptsJson();

        $paymentIntentId = \Craft::$app->getRequest()->getRequiredParam('paymentIntentId');
        $gatewayId = \Craft::$app->getRequest()->getRequiredParam('gatewayId');
        $setupFutureUsage = (bool)\Craft::$app->getRequest()->getParam('paymentIntent.setup_future_usage');

        if ($gateway = Plugin::getInstance()->getGateways()->getGatewayById($gatewayId)) {
            if ($gateway instanceof PaymentIntents) {
                $params = [
                    'setup_future_usage' => $setupFutureUsage ? 'off_session' : null,
                ];
                $paymentIntent = $gateway->getStripeClient()->paymentIntents->update($paymentIntentId,
                    $params
                );
            }
        }

        return $this->asJson(['success' => true]);
    }
}
