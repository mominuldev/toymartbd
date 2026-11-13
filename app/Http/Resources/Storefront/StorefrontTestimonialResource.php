<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Content\Models\Testimonial;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Content\ContentFormat;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/** @property Testimonial $resource */
#[SchemaName('StorefrontTestimonial')]
final class StorefrontTestimonialResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $t = $this->resource;

        return [
            'id' => $t->id,
            'name' => $t->name,
            /** @var string|null */
            'name_bn' => $t->name_bn,
            /** @var string|null */
            'location' => $t->location,
            /** @var string|null */
            'location_bn' => $t->location_bn,
            'quote' => $t->quote,
            /** @var string|null */
            'quote_bn' => $t->quote_bn,
            /** @var int|null */
            'rating' => $t->rating,
            /** @var array{media_id: int, url: string, srcset: string|null, width: int|null, height: int|null, alt: string|null, alt_bn: string|null}|null */
            'photo' => ContentFormat::image($t->media, 160),
        ];
    }
}
