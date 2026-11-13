<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Fulfilment\Actions\ReceiveCourierWebhook;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Webhooks\CourierWebhookRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Courier webhooks (P6, routes/api_v1_webhooks.php rules). Unauthenticated by design:
 * the driver verifies the signature on the raw body before anything is parsed or written
 * (rule 19); a verified event is recorded once (replay = no-op) and only TRIGGERS a queued
 * re-query of the provider's status API — the body is never trusted (rule 24c). Answers
 * fast with the acknowledgement the provider requires.
 */
final class CourierWebhookController extends ApiController
{
    #[OpenApiResponse(401, 'The signature did not verify — nothing was written')]
    #[OpenApiResponse(404, 'Unknown courier provider (`not-found`)')]
    public function __invoke(CourierWebhookRequest $request, string $provider, ReceiveCourierWebhook $receive): JsonResponse
    {
        $ack = $receive->handle($provider, $request->allHeaders(), $request->rawBody(), $request->keptHeaders());

        return response()
            ->json(['received' => true, 'replayed' => $ack['replayed'], 'ignored' => $ack['ignored']], $ack['status'])
            ->withHeaders($ack['headers']);
    }
}
