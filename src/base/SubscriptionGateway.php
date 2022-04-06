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
use craft\commerce\elements\db\SubscriptionQuery;
use craft\commerce\elements\Subscription;
use craft\commerce\errors\SubscriptionException;
use craft\commerce\models\Currency;
use craft\commerce\models\subscriptions\CancelSubscriptionForm as BaseCancelSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionForm as BaseSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionPayment;
use craft\commerce\models\subscriptions\SwitchPlansForm;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\events\CreateInvoiceEvent;
use craft\commerce\stripe\models\forms\CancelSubscription;
use craft\commerce\stripe\models\forms\Subscription as SubscriptionForm;
use craft\commerce\stripe\models\forms\SwitchPlans;
use craft\commerce\stripe\models\Invoice;
use craft\commerce\stripe\models\Plan;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\SubscriptionResponse;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\View;
use Stripe\ApiResource;
use Stripe\Invoice as StripeInvoice;
use Stripe\Plan as StripePlan;
use Stripe\Product as StripeProduct;
use Stripe\Refund;
use Stripe\Subscription as StripeSubscription;
use Stripe\SubscriptionItem;
use Throwable;
use yii\base\InvalidConfigException;
use function count;

/**
 * This class represents the abstract Stripe base gateway
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
abstract class SubscriptionGateway extends Gateway
{
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
    public const EVENT_CREATE_INVOICE = 'createInvoice';

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
    public const EVENT_BEFORE_SUBSCRIBE = 'beforeSubscribe';

    /**
     * string The Stripe API version to use.
     */
    public const STRIPE_API_VERSION = '2019-03-14';

    /**
     * @inheritdoc
     */
    public function cancelSubscription(Subscription $subscription, BaseCancelSubscriptionForm $parameters): SubscriptionResponseInterface
    {
        $this->configureStripeClient();
        try {
            $stripeSubscription = StripeSubscription::retrieve($subscription->reference);

            /** @var CancelSubscription $parameters */
            if ($parameters->cancelImmediately) {
                $response = $stripeSubscription->cancel();
            } else {
                $stripeSubscription->cancel_at_period_end = true;
                $response = $stripeSubscription->save();
            }

            return $this->createSubscriptionResponse($response);
        } catch (Throwable $exception) {
            throw new SubscriptionException('Failed to cancel subscription: ' . $exception->getMessage());
        }
    }

    /**
     * @inheritdoc
     */
    public function getCancelSubscriptionFormHtml(Subscription $subscription): string
    {
        $this->configureStripeClient();
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
        $this->configureStripeClient();
        return new CancelSubscription();
    }

    /**
     * @inheritdoc
     */
    public function getNextPaymentAmount(Subscription $subscription): string
    {
        $this->configureStripeClient();
        $data = $subscription->getSubscriptionData();
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
        $this->configureStripeClient();
        return new Plan();
    }

    /**
     * @inheritdoc
     */
    public function getPlanSettingsHtml(array $params = []): ?string
    {
        $this->configureStripeClient();
        return Craft::$app->getView()->renderTemplate('commerce-stripe/planSettings', $params);
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionFormModel(): BaseSubscriptionForm
    {
        $this->configureStripeClient();
        return new SubscriptionForm();
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPayments(Subscription $subscription): array
    {
        $this->configureStripeClient();
        $payments = [];

        $invoices = StripePlugin::getInstance()->getInvoices()->getSubscriptionInvoices($subscription->id);

        foreach ($invoices as $invoice) {
            $data = $invoice->invoiceData;

            $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso(strtoupper($data['currency']));

            if (!$currency) {
                Craft::warning('Unsupported currency - ' . $data['currency'], 'stripe');
                continue;
            }

            $data['created'] = isset($data['date']) && $data['date'] ? $data['date'] : $data['created'];
            $payments[$data['created']] = $this->createSubscriptionPayment($data, $currency);
        }

        // Sort them by time invoiced, not the time they were saved to DB
        krsort($payments);

        return $payments;
    }

    /**
     * @inheritdoc
     */
    public function refreshpaymenthistory(Subscription $subscription): void
    {
        $this->configureStripeClient();
        // Update the subscription period.
        $reference = $subscription->reference;
        $stripeSubscription = StripeSubscription::retrieve($reference);
        $subscription->nextPaymentDate = DateTimeHelper::toDateTime($stripeSubscription['current_period_end']);
        Craft::$app->getElements()->saveElement($subscription);

        /** @var StripeInvoice[] $invoices */
        $invoices = [];
        $after = false;

        // Fetch _all_ the invoices
        do {
            $params = [
                'subscription' => $reference,
                'limit' => 50,
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
                $this->saveSubscriptionInvoice($invoice->toArray(), $subscription);
            }
        }
    }


    /**
     * @inheritdoc
     */
    public function getSubscriptionPlanByReference(string $reference): string
    {
        $this->configureStripeClient();
        if (empty($reference)) {
            return '';
        }

        $plan = StripePlan::retrieve($reference);
        $plan = $plan->toArray();

        $product = StripeProduct::retrieve($plan['product']);
        $product = $product->toArray();

        return Json::encode(compact('plan', 'product'));
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPlans(): array
    {
        $this->configureStripeClient();
        $plans = StripePlan::all([
            'limit' => 100,
        ]);
        $output = [];

        $planProductMap = [];
        $planList = [];

        if (count($plans->data)) {
            foreach ($plans->data as $plan) {
                /** @var StripePlan $plan */
                $plan = $plan->toArray();
                $planProductMap[$plan['id']] = $plan['product'];
                $planList[] = $plan;
            }

            $products = StripeProduct::all([
                'limit' => 100,
                'ids' => array_values($planProductMap),
            ]);

            $productList = [];

            if (count($products->data)) {
                foreach ($products->data as $product) {
                    /** @var StripeProduct $product */
                    $product = $product->toArray();
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
        $this->configureStripeClient();
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
        $this->configureStripeClient();
        return new SwitchPlans();
    }

    /**
     * @inheritdoc
     */
    public function reactivateSubscription(Subscription $subscription): SubscriptionResponseInterface
    {
        $this->configureStripeClient();
        /** @var Plan $plan */
        $plan = $subscription->getPlan();

        $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
        /** @var SubscriptionItem $item */
        $item = $stripeSubscription->items->data[0];
        $stripeSubscription->items = [
            [
                'id' => $item->id,
                'plan' => $plan->reference,
            ],
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
        $this->configureStripeClient();
        /** @var SwitchPlans $parameters */
        $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
        /** @var SubscriptionItem $item */
        $item = $stripeSubscription->items->data[0];
        $stripeSubscription->items = [
            [
                'id' => $item->id,
                'plan' => $plan->reference,
            ],
        ];
        /** @phpstan-ignore-next-line */
        $stripeSubscription->prorate = (bool)$parameters->prorate;

        if ($parameters->billingCycleAnchor) {
            $stripeSubscription->billing_cycle_anchor = $parameters->billingCycleAnchor;
        }

        if ($parameters->quantity) {
            $stripeSubscription->items[0]['quantity'] = $parameters->quantity;
        }

        if ($parameters->prorationDate) {
            /** @phpstan-ignore-next-line */
            $stripeSubscription->proration_date = $parameters->prorationDate;
        }

        $response = $this->createSubscriptionResponse($stripeSubscription->save());

        // Bill immediately only for non-trials
        if (!$subscription->getIsOnTrial() && $parameters->billImmediately) {
            try {
                StripeInvoice::create([
                    'customer' => $stripeSubscription->customer,
                    'subscription' => $stripeSubscription->id,
                ]);
            } catch (Throwable $exception) {
                // Or, maybe, Stripe already invoiced them because reasons.
            }
        }

        return $response;
    }

    /**
     * Preview a subscription plan switch cost for a subscription.
     *
     * @param Subscription $subscription
     * @param BasePlan $plan
     * @return float
     * @throws \Stripe\Exception\ApiErrorException
     * @throws \yii\base\InvalidConfigException
     */
    public function previewSwitchCost(Subscription $subscription, BasePlan $plan): float
    {
        $this->configureStripeClient();
        $stripeSubscription = StripeSubscription::retrieve($subscription->reference);
        /** @var SubscriptionItem $item */
        $item = $stripeSubscription->items->data[0];

        $items = [
            [
                'id' => $item->id,
                'plan' => $plan->reference,
            ],
        ];

        $invoice = StripeInvoice::upcoming([
            'customer' => $stripeSubscription->customer,
            'subscription' => $subscription->reference,
            'subscription_items' => $items,
            'subscription_billing_cycle_anchor' => 'now',
        ]);

        $currency = Commerce::getInstance()->getCurrencies()->getCurrencyByIso(strtoupper($invoice->currency));

        return $currency ? $invoice->total / (10 ** $currency->minorUnit) : $invoice->total;
    }

    /**
     * @inheritdoc
     */
    public function handleWebhook(array $data): void
    {
        $this->configureStripeClient();
        switch ($data['type']) {
            case 'payment_method.detached':
                $this->handlePaymentMethodDetached($data);
                // no break
            case 'charge.refund.updated':
                $this->handleRefundUpdated($data);
                // no break
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
        $this->configureStripeClient();
        return new SubscriptionPayment([
            'paymentAmount' => $data['amount_due'] / (10 ** $currency->minorUnit),
            'paymentCurrency' => $currency,
            'paymentDate' => $data['created'],
            'paymentReference' => $data['charge'],
            'paid' => $data['paid'],
            'response' => Json::encode($data),
        ]);
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
        $this->configureStripeClient();
        $data = $resource->toArray();

        return new SubscriptionResponse($data);
    }

    /**
     * Handle an updated refund by updating the refund transaction.
     *
     * @param array $data
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    protected function handlePaymentMethodDetached(array $data)
    {
        $stripePaymentMethod = $data['data']['object'];
        if ($paymentSource = Commerce::getInstance()->getPaymentSources()->getPaymentSourceByTokenAndGatewayId($stripePaymentMethod['id'], $this->id)) {
            Commerce::getInstance()->getPaymentSources()->deletePaymentSourceById($paymentSource->id);
        }
    }

    /**
     * Handle an updated refund by updating the refund transaction.
     *
     * @param array $data
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    protected function handleRefundUpdated(array $data)
    {
        $stripeRefund = $data['data']['object'];
        if ($transaction = Commerce::getInstance()->getTransactions()->getTransactionByReference($stripeRefund['id'])) {
            $transactionRecord = TransactionRecord::findOne($transaction->id);
            switch ($stripeRefund['status']) {
                case Refund::STATUS_SUCCEEDED:
                    $transactionRecord->status = TransactionRecord::STATUS_SUCCESS;
                    break;
                case Refund::STATUS_PENDING:
                    $transactionRecord->status = TransactionRecord::STATUS_PROCESSING;
                    break;
                case Refund::STATUS_FAILED:
                    $transactionRecord->status = TransactionRecord::STATUS_FAILED;
                    $transactionRecord->message = $stripeRefund['failure_reason'];
                    break;
                default:
                    $transactionRecord->status = TransactionRecord::STATUS_FAILED;
            }
            $transactionRecord->response = $data['data'];
            // Need to update the record directly as commerce does not allow updating a transaction normally through the service
            $transactionRecord->save(false);
            $transaction->getOrder()->updateOrderPaidInformation();
        }
    }

    /**
     * Handle a created invoice.
     *
     * @param array $data
     * @throws \Stripe\Exception\ApiErrorException
     */
    protected function handleInvoiceCreated(array $data): void
    {
        $this->configureStripeClient();
        $stripeInvoice = $data['data']['object'];

        if ($this->hasEventHandlers(self::EVENT_CREATE_INVOICE)) {
            $this->trigger(self::EVENT_CREATE_INVOICE, new CreateInvoiceEvent([
                'invoiceData' => $stripeInvoice,
            ]));
        }

        $canBePaid = empty($stripeInvoice['paid']) && $stripeInvoice['billing'] === 'charge_automatically';

        if (StripePlugin::getInstance()->getSettings()->chargeInvoicesImmediately && $canBePaid) {
            $invoice = StripeInvoice::retrieve($stripeInvoice['id']);
            $invoice->pay();
        }
    }

    /**
     * Handle a successful invoice payment event.
     *
     * @param array $data
     * @throws Throwable if something went wrong when processing the invoice
     */
    protected function handleInvoiceSucceededEvent(array $data): void
    {
        $this->configureStripeClient();
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
            $subscription = Subscription::find()->reference($subscriptionReference)->anyStatus()->one();
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
     * @throws InvalidConfigException If plan not available
     */
    protected function handlePlanEvent(array $data): void
    {
        $this->configureStripeClient();
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
     * @throws Throwable
     */
    protected function handleSubscriptionExpired(array $data): void
    {
        $this->configureStripeClient();
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
     * @throws Throwable
     */
    protected function handleSubscriptionUpdated(array $data): void
    {
        $this->configureStripeClient();
        $stripeSubscription = $data['data']['object'];
        $subscription = Subscription::find()->status(null)->reference($stripeSubscription['id'])->one();

        if (!$subscription) {
            Craft::warning('Subscription with the reference “' . $stripeSubscription['id'] . '” not found when processing webhook ' . $data['id'], 'stripe');

            return;
        }

        // See if we care about this subscription at all
        $subscription->setSubscriptionData($data['data']['object']);

        $this->setSubscriptionStatusData($subscription);

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

    /**
     * Set the various status properties on a Subscription by the subscription data set on it.
     *
     * @param Subscription $subscription
     * @throws \Exception
     */
    protected function setSubscriptionStatusData(Subscription $subscription): void
    {
        $this->configureStripeClient();
        $subscriptionData = $subscription->getSubscriptionData();
        $canceledAt = $subscriptionData['canceled_at'];
        $endedAt = $subscriptionData['ended_at'];
        $status = $subscriptionData['status'];

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
                $timeLastInvoiceCreated = $subscriptionData['latest_invoice']['created'] ?? null;
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
        $subscription->nextPaymentDate = DateTimeHelper::toDateTime($subscriptionData['current_period_end']);
    }

    /**
     * Save a subscription invoice.
     *
     * @param array $stripeInvoice
     * @param Subscription $subscription
     * @return Invoice
     * @throws \yii\base\Exception
     */
    protected function saveSubscriptionInvoice(array $stripeInvoice, Subscription $subscription): Invoice
    {
        $this->configureStripeClient();
        $invoiceService = StripePlugin::getInstance()->getInvoices();
        $invoice = $invoiceService->getInvoiceByReference($stripeInvoice['id']) ?: new Invoice();
        $invoice->subscriptionId = $subscription->id;
        $invoice->reference = $stripeInvoice['id'];
        $invoice->invoiceData = $stripeInvoice;
        $invoiceService->saveInvoice($invoice);

        return $invoice;
    }
}
