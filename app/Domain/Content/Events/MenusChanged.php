<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A storefront menu was saved, published or unpublished. Held until the transaction commits. */
final readonly class MenusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
}
