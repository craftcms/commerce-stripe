<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Http\Controllers;

use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\CommerceStripe\Gateway;
use CraftCms\CommerceStripe\PaymentMethods;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Syncs the payment methods of every customer in a gateway’s Stripe account to Commerce payment sources.
 */
readonly class SyncPaymentSourcesController
{
    use RespondsWithFlash;

    public function __invoke(Request $request, Gateways $gateways, PaymentMethods $paymentMethods): Response
    {
        $gatewayId = (int)$request->input('gatewayId');
        $gateway = $gatewayId ? $gateways->getGatewayById($gatewayId) : null;

        if (!$gateway instanceof Gateway) {
            return $this->asFailure(t('No valid gateway ID provided.', category: 'commerce-stripe'));
        }

        try {
            $count = $paymentMethods->syncAllPaymentMethods($gateway);
        } catch (Throwable $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(t('Synced {count} payment sources.', ['count' => $count], category: 'commerce-stripe'));
    }
}
