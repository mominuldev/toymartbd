<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Models\Testimonial;
use Illuminate\Database\Eloquent\Collection;

/**
 * Published testimonials in display order — the read side the homepage testimonials block (and
 * `GET /storefront/testimonials`) uses. Cache tag: `content:testimonials`.
 */
final class PublishedTestimonials
{
    public const int MAX = 24;

    /** @return Collection<int, Testimonial> */
    public function list(int $limit = 12): Collection
    {
        return Testimonial::query()
            ->with('media')
            ->where('is_published', true)
            ->orderBy('position')
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX)))
            ->get();
    }
}
