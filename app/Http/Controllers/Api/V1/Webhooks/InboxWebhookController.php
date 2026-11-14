<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Inbox\Actions\ReceiveInboxWebhook;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Webhooks\InboxWebhookRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Inbox webhooks — WhatsApp Cloud API and Messenger (P10). Unauthenticated by design:
 *
 *  - GET  is Meta's subscription handshake: echo `hub.challenge` as plain text when
 *    `hub.mode=subscribe` and `hub.verify_token` matches; otherwise 403.
 *  - POST verifies X-Hub-Signature-256 on the raw bytes BEFORE anything is parsed or
 *    written (rule 19; 401 otherwise), records the body once and queues the processing.
 *
 * Both channels stay UNVERIFIED: no WhatsApp Business account or Facebook Page exists.
 */
final class InboxWebhookController extends ApiController
{
    #[OpenApiResponse(403, 'Wrong mode or verify token (`forbidden`)')]
    #[OpenApiResponse(404, 'A channel that takes no webhooks (`not-found`)')]
    public function verify(Request $request, string $channel, ReceiveInboxWebhook $receive): Response
    {
        // PHP turns `hub.mode` into `hub_mode` in $_GET; read the raw query string instead.
        $query = self::rawQuery((string) $request->server('QUERY_STRING'));
        $challenge = $receive->subscribe($channel, $query['hub.mode'] ?? null, $query['hub.verify_token'] ?? null, $query['hub.challenge'] ?? null);
        if ($challenge === null) {
            throw new AccessDeniedHttpException('Verification refused.');
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    #[OpenApiResponse(401, 'The signature did not verify — nothing was written')]
    #[OpenApiResponse(404, 'A channel that takes no webhooks (`not-found`)')]
    public function receive(InboxWebhookRequest $request, string $channel, ReceiveInboxWebhook $receive): JsonResponse
    {
        $ack = $receive->handle($channel, $request->allHeaders(), $request->rawBody());

        return response()->json(['received' => true, 'replayed' => $ack['replayed']]);
    }

    /** @return array<string, string> */
    private static function rawQuery(string $query): array
    {
        $values = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $values[urldecode($key)] = urldecode($value);
        }

        return $values;
    }
}
