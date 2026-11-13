<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Services\RedirectMap;
use Illuminate\Support\Facades\DB;

/** Remove a manual redirect. Rows collapsed through it already point at its target. */
final class DeleteRedirect
{
    public function handle(Redirect $redirect): void
    {
        DB::transaction(static fn () => $redirect->delete());

        RedirectMap::forget();
        SeoPagesStale::dispatch(['redirects']);
    }
}
