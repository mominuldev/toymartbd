<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Payment\Actions\HandleSslcommerzNotification;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Webhooks\SslcommerzNotificationRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * SSLCommerz IPN listener (P4-PAY-03, routes/api_v1_webhooks.php rules). Unauthenticated
 * by design: the optional source-IP allowlist, then the signature (before anything is
 * parsed or written), then a receipt and a queued re-query. Answers 200 fast; a replay is
 * a 200 no-op.
 */
final class SslcommerzWebhookController extends ApiController
{
    #[OpenApiResponse(401, 'The signature did not verify — nothing was written')]
    #[OpenApiResponse(403, 'The source IP is not on the configured allowlist')]
    public function ipn(SslcommerzNotificationRequest $request, HandleSslcommerzNotification $handler): JsonResponse
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('payments.sslcommerz.ipn_allowed_ips', []);
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            Log::warning('payment.sslcommerz.ipn_ip_refused');

            throw new AccessDeniedHttpException;
        }

        $received = $handler->receive($request->raw(), HandleSslcommerzNotification::SOURCE_WEBHOOK, $request->keptHeaders());

        return response()->json(['received' => true, 'replayed' => $received['replayed']]);
    }
}
