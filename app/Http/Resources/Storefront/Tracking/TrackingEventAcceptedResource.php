<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Tracking;

use App\Domain\Tracking\Models\TrackingEvent;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * `202` body of `POST /storefront/tracking/events`: the accepted event id. The same for a
 * duplicate post (idempotent) — nothing about dispatch is exposed to the storefront.
 *
 * @property TrackingEvent $resource
 */
#[SchemaName('StorefrontTrackingEventAccepted')]
final class TrackingEventAcceptedResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'event_id' => $this->resource->event_id,
        ];
    }
}
