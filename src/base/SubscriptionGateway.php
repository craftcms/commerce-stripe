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
use craft\commerce\models\PaymentSource;
use craft\commerce\models\subscriptions\CancelSubscriptionForm as BaseCancelSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionForm as BaseSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionPayment;
use craft\commerce\models\subscriptions\SwitchPlansForm;
use craft\commerce\Plugin;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\events\CreateInvoiceEvent;
use craft\commerce\stripe\models\forms\CancelSubscription;
use craft\commerce\stripe\models\forms\Subscription as SubscriptionForm;
use craft\commerce\stripe\models\forms\SwitchPlans;
use craft\commerce\stripe\models\Invoice;
use craft\commerce\stripe\models\Plan;
use craft\commerce\stripe\Plugin as StripePlugin;
use craft\commerce\stripe\responses\SubscriptionResponse;
use craft\errors\ElementNotFoundException;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\View;
use Stripe\ApiResource;
use Stripe\Invoice as StripeInvoice;
use Stripe\Plan as StripePlan;
use Stripe\Product as StripeProduct;
use Stripe\Refund;
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
     * @inheritdoc
     */
    public function cancelSubscription(Subscription $subscription, BaseCancelSubscriptionForm $parameters): SubscriptionResponseInterface
    {
        try {
            $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve($subscription->reference);

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
        $data = $subscription->getSubscriptionData();
        $currencyCode = strtoupper($data['plan']['currency']);
        $currency = CommercePlugin::getInstance()->getCurrencies()->getCurrencyByIso($currencyCode);

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
    public function getPlanSettingsHtml(array $params = []): ?string
    {
        $plansList = collect($this->getSubscriptionPlans())->mapWithKeys(function($plan) {
            return [$plan['reference'] => $plan['name']];
        })->all();

        $params = array_merge([
            'plansList' => $plansList,
        ], $params);
        return Craft::$app->getView()->renderTemplate('commerce-stripe/planSettings', $params);
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionFormModel(): BaseSubscriptionForm
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

            $currency = CommercePlugin::getInstance()->getCurrencies()->getCurrencyByIso(strtoupper($data['currency']));

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
        // Update the subscription period.
        $reference = $subscription->reference;
        $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve($reference);
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

            $list = $this->getStripeClient()->invoices->all($params);

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
        if (empty($reference)) {
            return '';
        }

        $plan = $this->getStripeClient()->plans->retrieve($reference);
        $plan = $plan->toArray();

        $product = $this->getStripeClient()->products->retrieve($plan['product']);
        $product = $product->toArray();

        return Json::encode(compact('plan', 'product'));
    }

    /**
     * @inheritdoc
     */
    public function getSubscriptionPlans(): array
    {
        $plans = $this->getStripeClient()->plans->all([
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

            $products = $this->getStripeClient()->products->all([
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

        $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve($subscription->reference);
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
        /** @var SwitchPlans $parameters */
        $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve($subscription->reference);
        /** @var SubscriptionItem $item */
        $item = $stripeSubscription->items->data[0];

        $request = [];

        $request['items'] = [
            [
                'id' => $item->id,
                'plan' => $plan->reference,
                'quantity' => $parameters->quantity ?: $item->quantity,
            ],
        ];

        if ($parameters->billingCycleAnchor) {
            $request['billing_cycle_anchor'] = $parameters->billingCycleAnchor;
        }

        if ($parameters->prorationDate) {
            $request['proration_date'] = $parameters->prorationDate;
        }

        if (!$parameters->prorate) {
            $request['proration_behavior'] = 'none';
        } else {
            if ($parameters->billImmediately) {
                $request['proration_behavior'] = 'always_invoice';
            } else {
                $request['proration_behavior'] = 'create_prorations';
            }
        }

        $stripeSubscription = $this->getStripeClient()->subscriptions->update($stripeSubscription->id, $request);
        $response = $this->createSubscriptionResponse($stripeSubscription);

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
        $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve($subscription->reference);
        /** @var SubscriptionItem $item */
        $item = $stripeSubscription->items->data[0];

        $items = [
            [
                'id' => $item->id,
                'plan' => $plan->reference,
            ],
        ];

        $invoice = $this->getStripeClient()->invoices->upcoming([
            'customer' => $stripeSubscription->customer,
            'subscription' => $subscription->reference,
            'subscription_items' => $items,
            'subscription_billing_cycle_anchor' => 'now',
        ]);

        $currency = CommercePlugin::getInstance()->getCurrencies()->getCurrencyByIso(strtoupper($invoice->currency));

        return $currency ? $invoice->total / (10 ** $currency->minorUnit) : $invoice->total;
    }

    /**
     * @inheritdoc
     */
    public function handleWebhook(array $data): void
    {
        switch ($data['type']) {
            case 'payment_method.attached':
            case 'payment_method.updated':
            case 'payment_method.automatically_updated':
                $this->handlePaymentMethodUpdated($data['data']['object']);
                break;
            case 'payment_method.detached':
                $this->handlePaymentMethodDetached($data);
                break;
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($data);
                break;
            case 'charge.refunded':
                $this->handleRefunded($data);
                break;
            case 'charge.refund.updated':
                $this->handleRefundUpdated($data);
                break;
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
            case 'customer.updated':
                $this->handleCustomerUpdated($data);
                break;
            case 'invoice.payment_failed':
                $this->handleInvoiceFailed($data);
                break;
        }

        parent::handleWebhook($data);
    }

    /**
     * @param array $data
     * @return void
     * @throws InvalidConfigException
     * @since 4.1.0
     */
    public function handlePaymentIntentSucceeded(array $data): void
    {
        $paymentIntent = $data['data']['object'];
        if ($paymentIntent['object'] === 'payment_intent') {
            $transaction = Plugin::getInstance()->getTransactions()->getTransactionByReference($paymentIntent['id']);
            $updateTransaction = null;

            if ($transaction->parentId === null) {
                $children = Plugin::getInstance()->getTransactions()->getChildrenByTransactionId($transaction->id);

                if (empty($children) && $transaction->status === TransactionRecord::STATUS_PROCESSING) {
                    $updateTransaction = $transaction;
                }

                foreach ($children as $child) {
                    if ($child->reference === $transaction->reference && $child->status === TransactionRecord::STATUS_PROCESSING && $paymentIntent['status'] === 'succeeded') {
                        $updateTransaction = $child;

                        break;
                    }
                }
            }

            if ($updateTransaction) {
                $transactionRecord = TransactionRecord::findOne($updateTransaction->id);
                $transactionRecord->status = TransactionRecord::STATUS_SUCCESS;
                $transactionRecord->message = '';
                $transactionRecord->response = $paymentIntent;

                $transactionRecord->save(false);
                $transaction->getOrder()->updateOrderPaidInformation();
            }
        }
    }

    /**
     * Handle a failed invoice by updating the subscription data for the subscription it failed.
     *
     * @param array $data
     * @throws Throwable
     * @throws ElementNotFoundException
     * @throws \yii\base\Exception
     */
    protected function handleInvoiceFailed(array $data): void
    {
        $stripeInvoice = $data['data']['object'];

        // Sanity check
        if ($stripeInvoice['paid']) {
            return;
        }

        $subscriptionReference = $stripeInvoice['subscription'] ?? null;

        if (!$subscriptionReference || !($subscription = Subscription::find()->status(null)->reference($subscriptionReference)->one())) {
            Craft::warning('Subscription with the reference “' . $subscriptionReference . '” not found when processing webhook ' . $data['id'], 'stripe');

            return;
        }

        $this->refreshSubscriptionData($subscription);
    }

    /**
     * @inheritdoc
     */
    public function getBillingIssueDescription(Subscription $subscription): string
    {
        $subscriptionData = $this->getExpandedSubscriptionData($subscription);
        $intentData = $subscriptionData['latest_invoice']['payment_intent'];

        if (in_array($subscriptionData['status'], ['incomplete', 'past_due', 'unpaid'])) {
            switch ($intentData['status']) {
                case 'requires_payment_method':
                    return $subscription->hasStarted ? Craft::t('commerce-stripe', 'To resume the subscription, please provide a valid payment method.') : Craft::t('commerce-stripe', 'To start the subscription, please provide a valid payment method.');
                case 'requires_action':
                    return $subscription->hasStarted ? Craft::t('commerce-stripe', 'To resume the subscription, please complete 3DS authentication.') : Craft::t('commerce-stripe', 'To start the subscription, please complete 3DS authentication.');
            }
        }

        return '';
    }

    /**
     * @inheritdoc
     */
    public function getHasBillingIssues(Subscription $subscription): bool
    {
        $subscription = $this->refreshSubscriptionData($subscription);
        $subscriptionData = $subscription->getSubscriptionData();
        $intentData = $subscriptionData['latest_invoice']['payment_intent'];

        return in_array($subscriptionData['status'], ['incomplete', 'past_due', 'unpaid']) && in_array($intentData['status'], ['requires_payment_method', 'requires_confirmation', 'requires_action']);
    }

    /**
     * Refresh a subscription's data.
     *
     * @param Subscription $subscription
     * @return Subscription
     * @throws \Stripe\Exception\ApiErrorException
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    protected function refreshSubscriptionData(Subscription $subscription): Subscription
    {
        $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve(
            $subscription->reference,
            [
                'expand' => ['latest_invoice.payment_intent'],
            ]);

        $subscription->setSubscriptionData($stripeSubscription->toArray());
        $this->setSubscriptionStatusData($subscription);
        Craft::$app->getElements()->saveElement($subscription);

        return $subscription;
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
        if ($paymentSource = CommercePlugin::getInstance()->getPaymentSources()->getPaymentSourceByTokenAndGatewayId($stripePaymentMethod['id'], $this->id)) {
            // We don’t care if it does not exist, just try to delete it
            CommercePlugin::getInstance()->getPaymentSources()->deletePaymentSourceById($paymentSource->id);
        }
    }

    /**
     * @param array $data
     * @return void
     * @throws InvalidConfigException
     */
    public function handlePaymentMethodUpdated(array $data)
    {
        $stripePaymentMethod = $data;

        // We only care about payment methods that have a customer
        if ($stripePaymentMethod['customer']) {

            // Do we have a local customer for this stripe customer?
            $customer = StripePlugin::getInstance()->getCustomers()->getCustomerByReference($stripePaymentMethod['customer']);

            // We don’t know who this customer is, so we cant do anything
            if (!$customer) {
                return;
            }

            $user = Craft::$app->getUsers()->getUserById($customer->userId);

            // Ensure customer actually exists in Stripe
            $stripeCustomer = $this->getStripeClient()->customers->retrieve($stripePaymentMethod['customer']);

            if (!$stripeCustomer) {
                return;
            }

            // See if we have a Commerce payment source for this stripe payment method already or create one
            $paymentSource = CommercePlugin::getInstance()->getPaymentSources()->getPaymentSourceByTokenAndGatewayId($stripePaymentMethod['id'], $this->id);

            if (!$paymentSource) {
                $paymentSource = new PaymentSource();
            }

            $paymentSource->gatewayId = $this->id;
            $paymentSource->token = $stripePaymentMethod['id'];
            $paymentSource->customerId = $user->id;
            $paymentSource->response = Json::encode($stripePaymentMethod);

            if (!$paymentSource->id || !$paymentSource->description) {
                $description = 'Stripe payment source';

                if ($stripePaymentMethod['type'] === 'card') {
                    $last4 = $stripePaymentMethod['card']['last4'];
                    $brand = $stripePaymentMethod['card']['brand'] ?: 'Card';
                    $description = Craft::t('commerce-stripe', '{cardType} ending in ••••{last4}', ['cardType' => StringHelper::upperCaseFirst($brand), 'last4' => $last4]);
                } elseif (isset($stripePaymentMethod[$stripePaymentMethod['type']], $stripePaymentMethod[$stripePaymentMethod['type']]['last4'])) {
                    $last4 = $stripePaymentMethod[$stripePaymentMethod['type']]['last4'];
                    $description = Craft::t('commerce-stripe', 'Payment method ending in ••••{last4}', ['last4' => $last4]);
                }

                $paymentSource->description = $description;
            }

            // No harm in making sure it is attached to the customer.
            $this->getStripeClient()->paymentMethods->attach($stripePaymentMethod['id'], ['customer' => $stripeCustomer->id]);

            $result = Plugin::getInstance()->paymentSources->savePaymentSource($paymentSource);

            if (!$result) {
                Craft::error('Could not save payment source: ' . Json::encode($paymentSource->getErrors()), 'commerce-stripe');
            }
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
        if ($transaction = CommercePlugin::getInstance()->getTransactions()->getTransactionByReference($stripeRefund['id'])) {
            $transactionRecord = TransactionRecord::findOne($transaction->id);
            switch ($stripeRefund['status']) {
                case Refund::STATUS_SUCCEEDED:
                    $transactionRecord->status = TransactionRecord::STATUS_SUCCESS;
                    $transactionRecord->message = "";
                    break;
                case Refund::STATUS_PENDING:
                    $transactionRecord->status = TransactionRecord::STATUS_PROCESSING;
                    $transactionRecord->message = "Processing";
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
     * Handle an updated refund by updating the refund transaction.
     *
     * @param array $data
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    protected function handleRefunded(array $data)
    {
        // We don’t need to handle this at the moment as we are handling the refund.updated event which also is triggered once the refund is refunded successfully.
    }

    /**
     * @param array $data
     * @return void
     */
    public function handleCustomerUpdated(array $data): void
    {
        $stripeCustomer = $data['data']['object'];

        $defaultPaymentMethod = $stripeCustomer['invoice_settings']['default_payment_method']
            ?? $stripeCustomer['default_source']
            ?? null;

        // Set the primary payment source for the user if it has changed
        if ($defaultPaymentMethod) {
            $customer = StripePlugin::getInstance()->getCustomers()->getCustomerByReference($stripeCustomer['id'], $this->id);
            if (!$customer) {
                return;
            }

            /** @var Gateway $gateway */
            $gateway = $customer->getGateway();
            if ($gateway->id != $this->id) {
                return;
            }

            $paymentSource = CommercePlugin::getInstance()->getPaymentSources()->getPaymentSourceByTokenAndGatewayId($defaultPaymentMethod, $this->id);
            if (!$paymentSource) {
                return;
            }

            $user = $customer->getUser();

            /** @phpstan-ignore-next-line */
            if ($user->getPrimaryPaymentSource() && $user->getPrimaryPaymentSource()->id == $paymentSource->id) {
                return;
            }

            /** @phpstan-ignore-next-line */
            $user->setPrimaryPaymentSourceId($paymentSource->id);

            Craft::$app->getElements()->saveElement($user, false);
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
        $stripeInvoice = $data['data']['object'];

        if ($this->hasEventHandlers(self::EVENT_CREATE_INVOICE)) {
            $this->trigger(self::EVENT_CREATE_INVOICE, new CreateInvoiceEvent([
                'invoiceData' => $stripeInvoice,
            ]));
        }

        $canBePaid = empty($stripeInvoice['paid']) && $stripeInvoice['billing'] === 'charge_automatically';

        if (StripePlugin::getInstance()->getSettings()->chargeInvoicesImmediately && $canBePaid) {
            $invoice = $this->getStripeClient()->invoices->retrieve($stripeInvoice['id']);
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
            $subscription = Subscription::find()->status(null)->reference($subscriptionReference)->one();
            $counter++;
        } while (!$subscription && $counter < $limit);


        if (!$subscription) {
            throw new SubscriptionException('Subscription with the reference “' . $subscriptionReference . '” not found when processing webhook ' . $data['id']);
        }

        $invoice = $this->saveSubscriptionInvoice($stripeInvoice, $subscription);

        $currency = CommercePlugin::getInstance()->getCurrencies()->getCurrencyByIso(strtoupper($stripeInvoice['currency']));
        $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve($subscriptionReference);
        $payment = $this->createSubscriptionPayment($invoice->invoiceData, $currency);

        CommercePlugin::getInstance()->getSubscriptions()->receivePayment($subscription, $payment, DateTimeHelper::toDateTime($stripeSubscription['current_period_end']));
    }

    /**
     * Handle Plan events
     *
     * @param array $data
     * @throws InvalidConfigException If plan not available
     */
    protected function handlePlanEvent(array $data): void
    {
        $planService = CommercePlugin::getInstance()->getPlans();

        $plan = $planService->getPlanByReference($data['data']['object']['id']);

        if (!$plan) {
            throw new InvalidConfigException('Plan with the reference “' . $data['data']['object']['id'] . '” not found when processing webhook ' . $data['id']);
        }

        if ($data['type'] == 'plan.deleted') {
            $planService->archivePlanById($plan->id);
            Craft::warning($plan->name . ' was archived because the corresponding plan was deleted on Stripe. (event "' . $data['id'] . '")', 'stripe');
        } else {
            $plan->planData = Json::encode($data['data']['object']);
            $planService->savePlan($plan);
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
        $stripeSubscription = $data['data']['object'];

        $subscription = Subscription::find()->status(null)->reference($stripeSubscription['id'])->one();

        if (!$subscription) {
            Craft::warning('Subscription with the reference “' . $stripeSubscription['id'] . '” not found when processing webhook ' . $data['id'], 'stripe');

            return;
        }

        CommercePlugin::getInstance()->getSubscriptions()->expireSubscription($subscription);
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
        // Fetch expanded data
        $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve(
            $data['data']['object']['id'],
            [
                'expand' => ['latest_invoice.payment_intent'],
            ]);

        // And nonchalantly replace it, before calling parent.
        $stripeSubscription = $data['data']['object'] = $stripeSubscription->toArray();

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
            $plan = CommercePlugin::getInstance()->getPlans()->getPlanByReference($planReference);

            if ($plan) {
                $subscription->planId = $plan->id;
            } else {
                Craft::warning($subscription->reference . ' was switched to a plan on Stripe that does not exist on this Site. (event "' . $data['id'] . '")', 'stripe');
            }
        }

        CommercePlugin::getInstance()->getSubscriptions()->updateSubscription($subscription);
    }

    /**
     * Set the various status properties on a Subscription by the subscription data set on it.
     *
     * @param Subscription $subscription
     * @throws \Exception
     */
    protected function setSubscriptionStatusData(Subscription $subscription): void
    {
        $subscriptionData = $subscription->getSubscriptionData();
        $canceledAt = $subscriptionData['canceled_at'];
        $endedAt = $subscriptionData['ended_at'];
        $status = $subscriptionData['status'];

        switch ($status) {
            // Somebody didn't manage to provide/authenticate a payment method
            case 'incomplete_expired':
                $subscription->isExpired = true;
                $subscription->dateExpired = $endedAt ? DateTimeHelper::toDateTime($endedAt) : null;
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
        $invoiceService = StripePlugin::getInstance()->getInvoices();
        $invoice = $invoiceService->getInvoiceByReference($stripeInvoice['id']) ?: new Invoice();
        $invoice->subscriptionId = $subscription->id;
        $invoice->reference = $stripeInvoice['id'];
        $invoice->invoiceData = $stripeInvoice;
        $invoiceService->saveInvoice($invoice);

        return $invoice;
    }

    /**
     * Get the expanded subscription data, including payment intent for latest invoice.
     *
     * @param Subscription $subscription
     * @return array
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function getExpandedSubscriptionData(Subscription $subscription): array
    {
        $subscriptionData = $subscription->getSubscriptionData();

        if (empty($subscriptionData['latest_invoice']['payment_intent'])) {
            $stripeSubscription = $this->getStripeClient()->subscriptions->retrieve(
                $subscription->reference,
                [
                    'expand' => ['latest_invoice.payment_intent'],
                ]);
            $subscriptionData = $stripeSubscription->toArray();
            $subscription->setSubscriptionData($subscriptionData);
        }

        return $subscriptionData;
    }
}
