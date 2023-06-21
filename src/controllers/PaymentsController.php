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
use Stripe\StripeClient;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * This controller provides functionality to...
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 */
class PaymentsController extends BaseController
{

}
