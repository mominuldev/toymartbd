<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Seo\Models\Redirect;
use Illuminate\Support\Facades\DB;

/** One storefront hit on a manual redirect: an atomic counter, no read-modify-write. */
final class RecordRedirectHit
{
    public function handle(int $id): bool
    {
        return DB::transaction(static fn (): bool => Redirect::query()->whereKey($id)->where('is_active', true)
            ->update(['hit_count' => DB::raw('hit_count + 1'), 'last_hit_at' => now()]) === 1);
    }
}
