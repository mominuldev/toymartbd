<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Payment\Actions\HandlePaystationNotification;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Webhooks\PaystationNotificationRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PayStation IPN listener. PayStation documents no signature, so — like Pathao (rule 24c)
 * — the IPN is a trigger to re-query, never authoritative: the optional source-IP
 * allowlist, then the invoice must be one of our PayStation payments (else 404, nothing
 * written), then a receipt and a queued re-query of transaction-status. Answers 200 fast;
 * a replay is a 200 no-op.
 */
final class PaystationWebhookController extends ApiController
{
    #[OpenApiResponse(403, 'The source IP is not on the configured allowlist')]
    #[OpenApiResponse(404, 'The invoice is not one of our PayStation payments — nothing was written')]
    public function ipn(PaystationNotificationRequest $request, HandlePaystationNotification $handler): JsonResponse
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('payments.paystation.ipn_allowed_ips', []);
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            Log::warning('payment.paystation.ipn_ip_refused');

            throw new AccessDeniedHttpException;
        }

        $payment = HandlePaystationNotification::payment($request->invoiceNumber());
        if ($payment === null) {
            Log::warning('payment.paystation.ipn_unknown_invoice');

            throw new NotFoundHttpException;
        }

        $received = $handler->receive($payment, $request->raw(), HandlePaystationNotification::SOURCE_WEBHOOK, $request->keptHeaders());

        return response()->json(['received' => true, 'replayed' => $received['replayed']]);
    }
}
