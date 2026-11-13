<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Events\TestimonialsChanged;
use App\Domain\Content\Models\Testimonial;
use Illuminate\Support\Facades\DB;

final class DeleteTestimonial
{
    public function handle(Testimonial $testimonial): void
    {
        DB::transaction(function () use ($testimonial): void {
            $testimonial->delete();
            TestimonialsChanged::dispatch();
        });
    }
}
