<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A testimonial was created, edited, deleted or reordered. Held until the transaction commits. */
final readonly class TestimonialsChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
}
