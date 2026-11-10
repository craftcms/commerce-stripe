<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Http\Controllers;

use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\CommerceStripe\Gateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Updates payment intents created by the payment form.
 */
readonly class PaymentsController
{
    /**
     * Sets whether a payment intent saves its payment method for future payments, when the customer toggles the
     * payment form’s “save payment source” checkbox.
     */
    public function savePaymentIntent(Request $request, Gateways $gateways): JsonResponse
    {
        abort_unless($request->expectsJson(), 400, 'Request must accept JSON in response');

        $paymentIntentId = $request->input('paymentIntentId');
        $gatewayId = $request->input('gatewayId');
        abort_if(!is_string($paymentIntentId) || $paymentIntentId === '', 400, 'Missing paymentIntentId');
        abort_if(!$gatewayId, 400, 'Missing gatewayId');

        $gateway = $gateways->getGatewayById((int)$gatewayId);

        if ($gateway instanceof Gateway) {
            $gateway->getStripeClient()->paymentIntents->update($paymentIntentId, [
                'setup_future_usage' => $request->boolean('paymentIntent.setup_future_usage') ? 'off_session' : null,
            ]);
        }

        return response()->json(['success' => true]);
    }
}
