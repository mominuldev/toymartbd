<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A banner was created, edited, deleted, or crossed a schedule boundary. Held until the transaction commits. */
final readonly class BannersChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
}
