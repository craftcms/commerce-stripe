<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\stripe\base;

use Craft;
use craft\commerce\base\Plan as BasePlan;
use craft\commerce\base\PlanInterface;
use craft\commerce\base\SubscriptionResponseInterface;
use craft\commerce\elements\Subscription;
use craft\commerce\errors\SubscriptionException;
use craft\commerce\models\Currency;
use craft\commerce\models\subscriptions\CancelSubscriptionForm as BaseCancelSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionPayment;
use craft\commerce\models\subscriptions\SwitchPlansForm;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\events\CreateInvoiceEvent;
use craft\commerce\stripe\models\forms\CancelSubscription;
use craft\commerce\stripe\models\forms\SwitchPlans;
use craft\commerce\stripe\models\Invoice;
use craft\commerce\stripe\models\Plan;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\SubscriptionResponse;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\View;
use Stripe\ApiResource;
use Stripe\Collection;
use Stripe\Invoice as StripeInvoice;
use Stripe\Plan as StripePlan;
use Stripe\Product as StripeProduct;
use Stripe\Subscription as StripeSubscription;

/**
 * This class represents the abstract Stripe base gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
abstract class SubscriptionGateway extends Gateway
{
    // Constants
    // =========================================================================

    /**
     * @event CreateInvoiceEvent The event that is triggered when an invoice is being created on the gateway.
     *
     * Plugins get a chance to do something when an invoice is created on the Stripe gateway.
     *
     * ```php
     * use craft\commerce\stripe\events\CreateInvoiceEvent;
     * use craft\commerce\stripe\base\SubscriptionGateway as StripeGateway;
     * use yii\base\Event;
     *
     * Event::on(StripeGateway::class, StripeGateway::EVENT_CREATE_INVOICE, function(CreateInvoiceEvent $e) {
     *     if ($e->invoiceData['billing'] === 'send_invoice') {
     *         // Forward this invoice to the accounting dpt.
     *     }
     * });
     * ```
     */
    const EVENT_CREATE_INVOICE = 'createInvoice';

    /**
     * @event SubscriptionRequestEvent The event that is triggered when a subscription request is being built.
     *
     * Plugins get a chance to tweak subscription parameters when subscribing.
     *
     * ```php
     * use craft\commerce\stripe\events\SubscriptionRequestEvent;
     * use craft\commerce\stripe\base\SubscriptionGateway as StripeGateway;
     * use yii\base\Event;
     *
     * Event::on(StripeGateway::class, StripeGateway::EVENT_BEFORE_SUBSCRIBE, function(SubscriptionRequestEvent $e) {
     *     $e->parameters['someKey'] = 'some value';
     *     unset($e->parameters['unneededKey']);
     * });
     * ```
     */
    const EVENT_BEFORE_SUBSCRIBE = 'beforeSubscribe';

    /**
     * string The Stripe API version to use.
     */
    const STRIPE_API_VERSION = '2019-03-14';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function cancelSubscription(Subscription $subscription, BaseCancelSubscriptionForm $parameters): SubscriptionResponseInterface
    {
        try {
            /** @var StripeSubscription $stripeSubscription */
            $stripeSubscription = StripeSubscription::retrieve($subscription->reference);

            /** @var CancelSubscription $parameters */
            if ($parameters->cancelImmediately) {
                $response = $stripeSubscription->cancel();
            } else {
                $stripeSubscription->cancel_at_period_end = true;
                $response = $stripeSubscription->save();
            }

            return $this->createSubscriptionResponse($response);
        } catch (\Throwable $exception) {
            throw new SubscriptionException('Failed to cancel subscription: ' . $exception->getMessage());
        }
    }

    /**
     * @inheritdoc
     */
    public function getCancelSubscriptionFormHtml(Subscription $subscription): string
    {
        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $view->renderTemplate('commerce-stripe/cancelSubscriptionForm', ['subscription' => $subscription]);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * @inheritdoc
     */
    public function getCancelSubscriptionFormModel(): BaseCancelSubscriptionForm
    {
        return new CancelSubscription();
    }

    /**
     * @inheritdoc
     */
    public function getNextPaymentAmount(Subscription $subscription): string
    {
        $data = $subscription->subscriptionData;
        $currencyCode = strtoupper($data['plan']['currency']);
        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso($currencyCode);

        if (!$currency) {
            Craft::warning('Unsupported currency - ' . $currencyCode, 'stripe');

            return (float)0;
        }

        return $data['plan']['amount'] / (10 ** $currency->minorUnit) . ' ' . $currencyCode;
    }

    /**
     * @inheritdoc
     */
    public function getPlanModel(): BasePlan
    {
        return new Plan();
    }

    /**
     * @inheritdoc
     */
    public function getPlanSettingsHtml(array $params = [])
    {
        return Craft::$app->getView()->renderTemplate('commerce-stripe/planSettings', $params);
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionFormModel(): SubscriptionForm
    {
        return new SubscriptionForm();
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPayments(Subscription $subscription): array
    {
        $payments = [];

        $invoices = StripePlugin::getInstance()->getInvoices()->getSubscriptionInvoices($subscription->id);

        foreach ($invoices as $invoice) {
            $data = $invoice->invoiceData;

            $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso(strtoupper($data['currency']));

            if (!$currency) {
                Craft::warning('Unsupported currency - ' . $data['currency'], 'stripe');
                continue;
            }

            $payments[$data['created']] = $this->createSubscriptionPayment($data, $currency);
        }

        // Sort them by time invoiced, not the time they were saved to DB
        krsort($payments);

        return $payments;
    }

    /**
     * @inheritdoc
     */
    public function refreshPaymentHistory(Subscription $subscription)
    {
        // Update the subscription period.
        $reference = $subscription->reference;
        $stripeSubscription = StripeSubscription::retrieve($reference);
        $subscription->nextPaymentDate = DateTimeHelper::toDateTime($stripeSubscription['current_period_end']);
        Craft::$app->getElements()->saveElement($subscription);

        $invoices = [];
        $after = false;

        // Fetch _all_ the invoices
        do {
            $params = [
                'subscription' => $reference,
                'limit' => 50
            ];

            // If we're paging, set the parameter
            if ($after) {
                $params['starting_after'] = $after;
            }

            $list = StripeInvoice::all($params);

            if (isset($list['data'])) {
                $data = $list['data'];
                $last = end($data);
                $after = $last['id'];

                // Merge the invoices together in a huge list
                $invoices = array_merge($invoices, $data);
            }
        } while ($list['has_more']);

        // Save the invoices.
        if (!empty($invoices)) {
            foreach ($invoices as $invoice) {
                $this->saveSubscriptionInvoice($invoice->jsonSerialize(), $subscription);
            }
        }
    }


    /**
     * @inheritdoc
     */
    public function getSubscriptionPlanByReference(string $reference): string
    {
        if (empty($reference)) {
            return '';
        }

        $plan = StripePlan::retrieve($reference);
        $plan = $plan->jsonSerialize();

        $product = StripeProduct::retrieve($plan['product']);
        $product = $product->jsonSerialize();

        return Json::encode(compact('plan', 'product'));
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPlans(): array
    {
        /** @var Collection $plans */
        $plans = StripePlan::all([
            'limit' => 100,
        ]);
        $output = [];

        $planProductMap = [];
        $planList = [];

        if (\count($plans->data)) {
            foreach ($plans->data as $plan) {
                /** @var StripePlan $plan */
                $plan = $plan->jsonSerialize();
                $planProductMap[$plan['id']] = $plan['product'];
                $planList[] = $plan;
            }

            /** @var Collection $products */
            $products = StripeProduct::all([
                'limit' => 100,
                'ids' => array_values($planProductMap),
            ]);

            $productList = [];

            if (\count($products->data)) {
                foreach ($products->data as $product) {
                    /** @var StripeProduct $product */
                    $product = $product->jsonSerialize();
                    $productList[$product['id']] = $product;
                }
            }

            foreach ($planList as $plan) {
                $productName = $productList[$plan['product']]['name'];
                $planName = null !== $plan['nickname'] ? ' (' . $plan['nickname'] . ')' : '';
                $output[] = ['name' => $productName . $planName, 'reference' => $plan['id']];
            }
        }

        return $output;
    }

    /**
     * @inheritdoc
     */
    public function getSwitchPlansFormHtml(PlanInterface $originalPlan, PlanInterface $targetPlan): string
    {
        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        /** @var Plan $originalPlan */
        /** @var Plan $targetPlan */
        $html = $view->renderTemplate('commerce-stripe/switchPlansForm', ['targetPlan' => $targetPlan, 'plansOnSameCycle' => $originalPlan->isOnSamePaymentCycleAs($targetPlan)]);

        $view->setTemplateMode($previousMode);

        return $html;
    }


    /**
     * @inheritdoc
     */
    public function getSwitchPlansFormModel(): SwitchPlansForm
    {
        return new SwitchPlans();
    }

    /**
     * @inheritdoc
     */
    public function reactivateSubscription(Subscription $subscription): SubscriptionResponseInterface
    {
        /** @var Plan $plan */
        $plan = $subscription->getPlan();

        /** @var StripeSubscription $stripeSubscription */
        $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
        $stripeSubscription->items = [
            [
                'id' => $stripeSubscription->items->data[0]->id,
                'plan' => $plan->reference,
            ]
        ];

        $stripeSubscription->cancel_at_period_end = false;

        return $this->createSubscriptionResponse($stripeSubscription->save());
    }

    /**
     * @inheritdoc
     */
    public function supportsPlanSwitch(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsReactivation(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function switchSubscriptionPlan(Subscription $subscription, BasePlan $plan, SwitchPlansForm $parameters): SubscriptionResponseInterface
    {
        /** @var SwitchPlans $parameters */
        /** @var StripeSubscription $stripeSubscription */
        $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
        $stripeSubscription->items = [
            [
                'id' => $stripeSubscription->items->data[0]->id,
                'plan' => $plan->reference,
            ]
        ];
        $stripeSubscription->prorate = (bool)$parameters->prorate;

        $response = $this->createSubscriptionResponse($stripeSubscription->save());

        // Bill immediately only for non-trials
        if (!$subscription->getIsOnTrial() && $parameters->billImmediately) {
            StripeInvoice::create([
                'customer' => $stripeSubscription->customer,
                'subscription' => $stripeSubscription->id
            ]);
        }

        return $response;
    }

    // Protected methods
    // =========================================================================

    /**
     * Create a subscription payment model from invoice.
     *
     * @param array $data
     * @param Currency $currency the currency used for payment
     *
     * @return SubscriptionPayment
     */
    protected function createSubscriptionPayment(array $data, Currency $currency): SubscriptionPayment
    {
        $payment = new SubscriptionPayment([
            'paymentAmount' => $data['amount_due'] / (10 ** $currency->minorUnit),
            'paymentCurrency' => $currency,
            'paymentDate' => $data['created'],
            'paymentReference' => $data['charge'],
            'paid' => $data['paid'],
            'response' => Json::encode($data)
        ]);

        return $payment;
    }

    /**
     * Create a Subscription Response object from an ApiResponse object.
     *
     * @param ApiResource $resource
     *
     * @return SubscriptionResponseInterface
     */
    protected function createSubscriptionResponse(ApiResource $resource): SubscriptionResponseInterface
    {
        $data = $resource->jsonSerialize();

        return new SubscriptionResponse($data);
    }

    /**
     * Handle a created invoice.
     *
     * @param array $data
     */
    protected function handleInvoiceCreated(array $data)
    {
        $stripeInvoice = $data['data']['object'];

        if ($this->hasEventHandlers(self::EVENT_CREATE_INVOICE)) {
            $this->trigger(self::EVENT_CREATE_INVOICE, new CreateInvoiceEvent([
                'invoiceData' => $stripeInvoice
            ]));
        }

        $canBePaid = empty($stripeInvoice['paid']) && $stripeInvoice['billing'] === 'charge_automatically';

        if (StripePlugin::getInstance()->getSettings()->chargeInvoicesImmediately && $canBePaid) {
            /** @var StripeInvoice $invoice */
            $invoice = StripeInvoice::retrieve($stripeInvoice['id']);
            $invoice->pay();
        }
    }

    /**
     * Handle a successful invoice payment event.
     *
     * @param array $data
     * @throws \Throwable if something went wrong when processing the invoice
     */
    protected function handleInvoiceSucceededEvent(array $data)
    {
        $stripeInvoice = $data['data']['object'];

        // Sanity check
        if (!$stripeInvoice['paid']) {
            return;
        }

        $subscriptionReference = $stripeInvoice['subscription'];

        $counter = 0;
        $limit = 5;

        do {
            // Handle cases when Stripe sends us a webhook so soon that we haven't processed the subscription that triggered the webhook
            sleep(1);
            $subscription = Subscription::find()->reference($subscriptionReference)->one();
            $counter++;
        } while (!$subscription && $counter < $limit);


        if (!$subscription) {
            throw new SubscriptionException('Subscription with the reference “' . $subscriptionReference . '” not found when processing webhook ' . $data['id']);
        }

        $invoice = $this->saveSubscriptionInvoice($stripeInvoice, $subscription);

        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso(strtoupper($stripeInvoice['currency']));
        $stripeSubscription = StripeSubscription::retrieve($subscriptionReference);
        $payment = $this->createSubscriptionPayment($invoice->invoiceData, $currency);

        Commerce::getInstance()->getSubscriptions()->receivePayment($subscription, $payment, DateTimeHelper::toDateTime($stripeSubscription['current_period_end']));
    }

    /**
     * Handle Plan events
     *
     * @param array $data
     * @throws \yii\base\InvalidConfigException If plan not available
     */
    protected function handlePlanEvent(array $data)
    {
        $planService = Commerce::getInstance()->getPlans();

        if ($data['type'] == 'plan.deleted') {
            $plan = $planService->getPlanByReference($data['data']['object']['id']);

            if ($plan) {
                $planService->archivePlanById($plan->id);
                Craft::warning($plan->name . ' was archived because the corresponding plan was deleted on Stripe. (event "' . $data['id'] . '")', 'stripe');
            }
        }
    }

    /**
     * Handle an expired subscription.
     *
     * @param array $data
     *
     * @throws \Throwable
     */
    protected function handleSubscriptionExpired(array $data)
    {
        $stripeSubscription = $data['data']['object'];

        $subscription = Subscription::find()->reference($stripeSubscription['id'])->anyStatus()->one();

        if (!$subscription) {
            Craft::warning('Subscription with the reference “' . $stripeSubscription['id'] . '” not found when processing webhook ' . $data['id'], 'stripe');

            return;
        }

        Commerce::getInstance()->getSubscriptions()->expireSubscription($subscription);
    }

    /**
     * Handle an updated subscription.
     *
     * @param array $data
     *
     * @throws \Throwable
     */
    protected function handleSubscriptionUpdated(array $data)
    {
        $stripeSubscription = $data['data']['object'];
        $canceledAt = $data['data']['object']['canceled_at'];
        $endedAt = $data['data']['object']['ended_at'];
        $status = $data['data']['object']['status'];

        $subscription = Subscription::find()->anyStatus()->reference($stripeSubscription['id'])->one();

        if (!$subscription) {
            Craft::warning('Subscription with the reference “' . $stripeSubscription['id'] . '” not found when processing webhook ' . $data['id'], 'stripe');

            return;
        }

        // See if we care about this subscription at all
        if ($subscription) {

            $subscription->setSubscriptionData($data['data']['object']);

            switch ($status) {
                // Somebody didn't manage to provide/authenticate a payment method
                case 'incomplete_expired':
                    $subscription->isExpired = true;
                    $subscription->dateExpired = $endedAt ? DateTimeHelper::toDateTime($endedAt) : null;
                    $subscription->isCanceled = false;
                    $subscription->dateCanceled = null;
                    $subscription->nextPaymentDate = null;
                    break;
                // Definitely not suspended
                case 'active':
                    $subscription->isSuspended = false;
                    $subscription->dateSuspended = null;
                    break;
                // Suspend this and make a guess at the suspension date
                case 'past_due':
                    $timeLastInvoiceCreated = $data['data']['object']['latest_invoice']['created'] ?? null;
                    $dateSuspended = $timeLastInvoiceCreated ? DateTimeHelper::toDateTime($timeLastInvoiceCreated) : null;
                    $subscription->dateSuspended = $subscription->isSuspended ? $subscription->dateSuspended : $dateSuspended;
                    $subscription->isSuspended = true;
                    break;
                case 'canceled':
                    $subscription->isExpired = true;
                    $subscription->dateExpired = $endedAt ? DateTimeHelper::toDateTime($endedAt) : null;
            }

            // Make sure we mark this as started, if appropriate
            $subscription->hasStarted = !in_array($status, ['incomplete', 'incomplete_expired']);

            // Update all the other tidbits
            $subscription->isCanceled = (bool)$canceledAt;
            $subscription->dateCanceled = $canceledAt ? DateTimeHelper::toDateTime($canceledAt) : null;
            $subscription->nextPaymentDate = DateTimeHelper::toDateTime($data['data']['object']['current_period_end']);

            if (empty($data['data']['object']['plan'])) {
                Craft::warning($subscription->reference . ' contains multiple plans, which is not supported. (event "' . $data['id'] . '")', 'stripe');
            } else {
                $planReference = $data['data']['object']['plan']['id'];
                $plan = Commerce::getInstance()->getPlans()->getPlanByReference($planReference);

                if ($plan) {
                    $subscription->planId = $plan->id;
                } else {
                    Craft::warning($subscription->reference . ' was switched to a plan on Stripe that does not exist on this Site. (event "' . $data['id'] . '")', 'stripe');
                }
            }

            Commerce::getInstance()->getSubscriptions()->updateSubscription($subscription);
        }
    }

    /**
     * Save a subscription invoice.
     *
     * @param $stripeInvoice
     * @param $subscription
     * @return Invoice
     */
    protected function saveSubscriptionInvoice(array $stripeInvoice, Subscription $subscription): Invoice
    {
        $invoiceService = StripePlugin::getInstance()->getInvoices();
        $invoice = $invoiceService->getInvoiceByReference($stripeInvoice['id']) ?: new Invoice();
        $invoice->subscriptionId = $subscription->id;
        $invoice->reference = $stripeInvoice['id'];
        $invoice->invoiceData = $stripeInvoice;
        $invoiceService->saveInvoice($invoice);

        return $invoice;
    }

    /**
     * @inheritdoc
     */
    protected function handleWebhook(array $data)
    {
        switch ($data['type']) {
            case 'plan.deleted':
            case 'plan.updated':
                $this->handlePlanEvent($data);
                break;
            case 'invoice.payment_succeeded':
                $this->handleInvoiceSucceededEvent($data);
                break;
            case 'invoice.created':
                $this->handleInvoiceCreated($data);
                break;
            case 'customer.subscription.deleted':
                $this->handleSubscriptionExpired($data);
                break;
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($data);
                break;
        }

        parent::handleWebhook($data);
    }


}
