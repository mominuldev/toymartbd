<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Events\BannersChanged;
use App\Domain\Content\Models\Banner;
use Illuminate\Support\Facades\DB;

/** Delete a banner. Banners are presentation, not records: nothing else references them. */
final class DeleteBanner
{
    public function handle(Banner $banner): void
    {
        DB::transaction(function () use ($banner): void {
            $banner->delete();
            BannersChanged::dispatch();
        });
    }
}
