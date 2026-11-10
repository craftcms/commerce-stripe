<?php

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\CommerceStripe\Http\Controllers\CustomersController;
use CraftCms\CommerceStripe\Http\Controllers\PaymentsController;
use CraftCms\CommerceStripe\Http\Controllers\SyncPaymentSourcesController;
use Illuminate\Support\Facades\Route;

// These routes are reachable at `actions/commerce-stripe/...` on the site and in the control panel.

// Anonymous on purpose: guests pay with the payment form, which calls this when the "save payment source" checkbox
// changes.
Route::post('payments/save-payment-intent', [PaymentsController::class, 'savePaymentIntent']);

Route::middleware('auth')->group(function() {
    Route::post('customers/billing-portal-redirect', [CustomersController::class, 'billingPortalRedirect']);
    Route::post('customers/create-setup-intent', [CustomersController::class, 'createSetupIntent']);
    // Stripe.js sends the customer here after confirming a setup intent.
    Route::get('customers/confirm-setup-intent', [CustomersController::class, 'confirmSetupIntent']);
});

Route::middleware(['auth', RequireAdmin::class])->group(function() {
    Route::post('default/sync-payment-sources', SyncPaymentSourcesController::class);
});
