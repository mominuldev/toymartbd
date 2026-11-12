<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Webhooks\SslcommerzWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbound webhooks — v1
|--------------------------------------------------------------------------
|
| Rules these routes must follow without exception (docs/SYSTEM-ARCHITECTURE.md §8):
|
|   1. Verify the signature or hash BEFORE any parsing or database write.
|      An unverified payload is logged and dropped with 401.
|   2. Re-verify the authoritative amount and status by calling the provider's
|      query API. Never trust a webhook body as the source of truth for money.
|   3. Idempotency on the provider's event id, stored in webhook_receipts.
|   4. Respond 200 fast; do all work in a queued job.
|   5. Store every raw inbound payload for 90 days for dispute resolution.
|
| Pathao is a special case: its X-PATHAO-Signature is a plain shared secret,
| not an HMAC, so a Pathao webhook is a trigger to re-query, never authoritative.
|
*/

Route::get('/ping', fn () => ['ok' => true, 'audience' => 'webhooks'])->name('ping');

// SSLCommerz IPN (P4-PAY-03): signature first, receipt (replay = no-op), queued re-query.
Route::post('/payments/sslcommerz/ipn', [SslcommerzWebhookController::class, 'ipn'])->name('payments.sslcommerz.ipn');
