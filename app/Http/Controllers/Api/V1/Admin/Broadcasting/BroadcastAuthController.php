<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Broadcasting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Broadcasting\AuthorizeChannelRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Broadcast;

/**
 * Live updates (Laravel Reverb) for the admin. Echo calls this to join a private channel.
 */
final class BroadcastAuthController extends ApiController
{
    /**
     * `POST /broadcasting/auth` — the Pusher-protocol signature `{ auth }` for one private channel.
     * Not a Resource: the shape is the Pusher protocol's, read only by laravel-echo.
     *
     * @response array{auth: string}
     */
    #[OpenApiResponse(403, 'The channel\'s Policy refuses this user, e.g. no inbox.view (`forbidden`)')]
    public function __invoke(AuthorizeChannelRequest $request): JsonResponse
    {
        /** @var mixed $signature */
        $signature = Broadcast::auth($request);

        return new JsonResponse(is_array($signature) ? $signature : []);
    }
}
