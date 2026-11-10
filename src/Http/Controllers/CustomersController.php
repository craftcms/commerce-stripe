<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Http\Controllers;

use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Flash;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\PaymentSources;
use CraftCms\CommerceStripe\Customers;
use CraftCms\CommerceStripe\Forms\PaymentIntentForm;
use CraftCms\CommerceStripe\Gateway;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;

/**
 * Manages the Stripe objects of the logged-in customer: billing portal sessions and setup intents.
 */
readonly class CustomersController
{
    use RespondsWithFlash;

    /**
     * Redirects the customer to the Stripe billing portal.
     */
    public function billingPortalRedirect(Request $request, Gateways $gateways): Response
    {
        $user = currentUserElement() ?? abort(401);
        $redirect = $this->getPostedRedirectUrl() ?? $request->path();

        // The param is named `gatewayId`, but it takes the gateway’s handle.
        $gateway = $gateways->getGatewayByHandle((string)$request->input('gatewayId'));

        if (!$gateway instanceof Gateway) {
            return $this->asFailure('Can not create billing portal link.');
        }

        $url = $gateway->getBillingPortalUrl($user, $redirect);

        if ($request->expectsJson()) {
            return response()->json(['redirect' => $url]);
        }

        return redirect()->away($url);
    }

    /**
     * Creates a setup intent for the logged-in customer, so the payment form can save a payment method.
     */
    public function createSetupIntent(Request $request, Gateways $gateways, Customers $customers): Response
    {
        abort_unless($request->expectsJson(), 400, 'Request must accept JSON in response');

        $user = currentUserElement() ?? abort(401);
        $gatewayId = (int)$request->input('gatewayId');

        if (!$gatewayId) {
            return $this->asFailure(t('Missing gateway ID.', category: 'commerce-stripe'));
        }

        try {
            $gateway = $gateways->getGatewayById($gatewayId);

            if (!$gateway instanceof Gateway) {
                return $this->asFailure(t('No valid gateway ID provided.', category: 'commerce-stripe'));
            }

            $setupIntent = $gateway->createSetupIntent([
                'customer' => $customers->getCustomer($gatewayId, $user)->reference,
            ]);

            return response()->json($setupIntent->toArray());
        } catch (Throwable $exception) {
            return $this->asFailure($exception->getMessage());
        }
    }

    /**
     * Saves the payment method of a confirmed setup intent as a payment source. Stripe.js sends the customer here
     * after confirming the setup intent, with the payment source form’s params in the query string.
     */
    public function confirmSetupIntent(Request $request, Gateways $gateways, Customers $customers, PaymentSources $paymentSources): Response
    {
        $user = currentUserElement() ?? abort(401);
        $gateway = $gateways->getGatewayById((int)$request->query('gatewayId'));
        abort_unless($gateway instanceof Gateway, 400, 'Invalid gateway ID.');

        $setupIntent = $gateway->getStripeClient()->setupIntents->retrieve((string)$request->query('setup_intent'));
        $message = null;

        switch ($setupIntent->status) {
            case 'succeeded':
            case 'processing':
                $message = $request->getSigned('successMessage');
                $customer = $customers->getCustomerByReference((string)$setupIntent->customer, $gateway->id);

                // The setup intent has to belong to the logged-in customer.
                abort_if($customer && $customer->userId !== $user->id, 403);

                if ($customer) {
                    $paymentForm = new PaymentIntentForm();
                    $paymentForm->paymentMethodId = is_string($setupIntent->payment_method) ? $setupIntent->payment_method : $setupIntent->payment_method?->id;

                    $paymentSources->createPaymentSource(
                        $customer->userId,
                        $gateway,
                        $paymentForm,
                        is_string($request->query('description')) ? $request->query('description') : null,
                        $request->boolean('isPrimaryPaymentSource'),
                    );
                }
                break;
            case 'requires_payment_method':
                Flash::error(t('Failed to process payment details. Please try another payment method.', category: 'commerce-stripe'));

                return redirect((string)$request->getSigned('cancelUrl'));
        }

        Flash::success($message ?? t('Payment source saved.', category: 'commerce-stripe'));

        return redirect((string)$request->getSigned('redirect'));
    }
}
