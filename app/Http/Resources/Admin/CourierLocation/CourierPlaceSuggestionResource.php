<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\CourierLocation;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A SUGGESTED place of ours for one courier row — name similarity only; a human confirms.
 *
 * @property array{district: District, upazila: Upazila|null, score: float} $resource
 */
#[SchemaName('CourierPlaceSuggestion')]
final class CourierPlaceSuggestionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource['district'];
        $u = $this->resource['upazila'];

        return [
            /** @var 'name_similarity' */
            'reason' => 'name_similarity',
            /** @var float */
            'score' => $this->resource['score'],
            /** @var int */
            'district_id' => $d->id,
            /** @var string */
            'district' => $d->name,
            /** @var string */
            'district_bn' => $d->name_bn,
            /** @var int|null */
            'upazila_id' => $u?->id,
            /** @var string|null */
            'upazila' => $u?->name,
            /** @var string|null */
            'upazila_bn' => $u?->name_bn,
        ];
    }
}
