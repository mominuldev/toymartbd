<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Events\TestimonialsChanged;
use App\Domain\Content\Models\Testimonial;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Set the display order: `$ids` must list every testimonial exactly once. */
final class ReorderTestimonials
{
    /** @param  list<int>  $ids */
    public function handle(array $ids): void
    {
        DB::transaction(function () use ($ids): void {
            $existing = Testimonial::query()->lockForUpdate()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $sorted = $ids;
            sort($sorted);

            if ($sorted !== $existing) {
                throw new InvalidArgumentException('The order must list every testimonial exactly once.');
            }

            foreach ($ids as $position => $id) {
                Testimonial::query()->whereKey($id)->update(['position' => $position + 1]);
            }

            TestimonialsChanged::dispatch();
        });
    }
}
