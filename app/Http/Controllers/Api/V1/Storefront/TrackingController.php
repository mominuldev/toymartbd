<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Tracking\Actions\RecordTrackingEvent;
use App\Domain\Tracking\Jobs\DispatchTrackingEvent;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Tracking\StoreTrackingEventRequest;
use App\Http\Resources\Storefront\Tracking\TrackingConfigResource;
use App\Http\Resources\Storefront\Tracking\TrackingEventAcceptedResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Server-side tracking for the storefront (PRD K-01..K-03, docs/api/tracking.md §2). Called
 * by the Next.js server only, with its storefront token (rule 28).
 */
final class TrackingController extends ApiController
{
    /**
     * `GET /storefront/tracking/config` — the public tracking ids, flags and consent mode.
     * The storefront caches it under the `tracking-config` tag; an admin save revalidates it.
     */
    public function config(TrackingSettings $settings): TrackingConfigResource
    {
        return new TrackingConfigResource($settings->publicConfig());
    }

    /**
     * `POST /storefront/tracking/events` — record the server twin of a browser event and
     * queue its dispatch. Idempotent on `(event_id, event_name)`: a repeat is accepted and
     * not dispatched again. Always `202`: the dispatch outcome is in the admin diagnostics.
     */
    #[OpenApiResponse(422, 'Invalid field or an event name outside the allowlist (`validation-error`)')]
    #[OpenApiResponse(429, 'Too many events for this shopper (`too-many-requests`)')]
    public function store(StoreTrackingEventRequest $request, RecordTrackingEvent $record): JsonResponse
    {
        ['event' => $event, 'created' => $created] = $record->handle($request->toRecord());
        if ($created) {
            DispatchTrackingEvent::dispatch($event->id, $request->client(), $request->string('event_source_url')->toString());
        }

        return (new TrackingEventAcceptedResource($event))->response()->setStatusCode(202);
    }
}
