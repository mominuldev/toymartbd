<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\CourierLocation;

use App\Domain\Fulfilment\Models\CourierLocation;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A SUGGESTED courier zone for one of our places — ranked by name similarity only, and
 * never applied without a human's confirm (rule 32).
 *
 * @property array{location: CourierLocation, score: float} $resource
 */
#[SchemaName('CourierZoneSuggestion')]
final class CourierZoneSuggestionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'name_similarity' */
            'reason' => 'name_similarity',
            /**
             * 0–1 name similarity (city ~ district, zone ~ upazila). Not a confidence in the mapping.
             *
             * @var float
             */
            'score' => $this->resource['score'],
            'location' => new CourierLocationResource($this->resource['location']),
        ];
    }
}
