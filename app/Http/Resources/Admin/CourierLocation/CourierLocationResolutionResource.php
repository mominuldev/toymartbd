<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\CourierLocation;

use App\Domain\Fulfilment\Locations\LocationResolution;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Which courier ids an address would book into. `ambiguous` lists the mapped candidates
 * staff must choose between; `unmapped` blocks booking — nothing is ever guessed (rule 32).
 *
 * @property LocationResolution $resource
 */
#[SchemaName('CourierLocationResolution')]
final class CourierLocationResolutionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var 'not_required'|'resolved'|'ambiguous'|'unmapped' */
            'status' => $r->status,
            'can_book' => $r->canBook(),
            /** @var array{city_id: string|null, zone_id: string|null, area_id: string|null}|null */
            'ref' => $r->ref === null ? null : ['city_id' => $r->ref->externalCityId, 'zone_id' => $r->ref->externalZoneId, 'area_id' => $r->ref->externalAreaId],
            'candidates' => CourierLocationResource::collection($r->candidates),
        ];
    }
}
