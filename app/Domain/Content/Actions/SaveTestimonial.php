<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\DTOs\TestimonialData;
use App\Domain\Content\Events\TestimonialsChanged;
use App\Domain\Content\Models\Testimonial;
use Illuminate\Support\Facades\DB;

/** Create or replace a testimonial. A new one goes to the end of the order. */
final class SaveTestimonial
{
    public function handle(?Testimonial $testimonial, TestimonialData $data): Testimonial
    {
        return DB::transaction(function () use ($testimonial, $data): Testimonial {
            if ($testimonial === null) {
                $testimonial = new Testimonial;
                $testimonial->position = ((int) Testimonial::query()->lockForUpdate()->max('position')) + 1;
            }

            $testimonial->fill([
                'name' => trim($data->name),
                'name_bn' => self::text($data->nameBn),
                'location' => self::text($data->location),
                'location_bn' => self::text($data->locationBn),
                'quote' => trim($data->quote),
                'quote_bn' => self::text($data->quoteBn),
                'rating' => $data->rating,
                'media_id' => $data->mediaId,
                'is_published' => $data->isPublished,
            ]);

            if ($testimonial->exists && ! $testimonial->isDirty()) {
                return $testimonial;
            }

            $testimonial->save();
            TestimonialsChanged::dispatch();

            return $testimonial;
        });
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
