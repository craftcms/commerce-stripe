<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\gateways;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\base\SubscriptionGateway as BaseGateway;
use craft\commerce\stripe\web\assets\chargeform\ChargeFormAsset;
use craft\web\View;

/**
 * This class represents the Stripe Charge gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Gateway extends BaseGateway
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public $publishableKey;

    /**
     * @var string
     */
    public $apiKey;

    /**
     * @var bool
     */
    public $sendReceiptEmail;

    /**
     * @var bool
     */
    public $enforce3dSecure;

    /**
     * @var string
     */
    public $signingSecret;

    // Public methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce-stripe', 'Stripe Charge');
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params)
    {
        $defaults = [
            'gateway' => $this,
            'paymentForm' => $this->getPaymentFormModel(),
        ];

        $params = array_merge($defaults, $params);

        // If there's no order passed, add the current cart if we're not messing around in backend.
        if (!isset($params['order']) && !Craft::$app->getRequest()->getIsCpRequest()) {
            $params['order'] = Commerce::getInstance()->getCarts()->getCart();
        }
        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerJsFile('https://js.stripe.com/v3/');
        $view->registerAssetBundle(ChargeFormAsset::class);

        $html = $view->renderTemplate('commerce-stripe/paymentForms/chargeForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    protected function handleWebhook(array $data) {
        if (!empty($data['data']['object']['metadata']['three_d_secure_flow'])) {
            $this->handle3DSecureFlowEvent($data);
        }

        parent::handleWebhook($data);
    }
}
