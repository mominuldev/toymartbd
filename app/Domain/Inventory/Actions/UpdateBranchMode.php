<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\BranchModeConflict;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\BranchMode;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves Branch setup: single shop or multiple branches ({@see BranchMode}).
 *
 * Going single is refused while any location other than the default is active — those
 * branches must be switched off first (which itself needs them empty of stock), so no unit
 * ever disappears from a count. The active location rows are locked in ascending id order, the
 * same order CreateStockLocation and UpdateStockLocation lock them, so a concurrent "add a
 * branch" and "go single" cannot both pass.
 */
final readonly class UpdateBranchMode
{
    public function handle(bool $multi, ?int $userId): void
    {
        DB::transaction(function () use ($multi, $userId): void {
            $active = StockLocation::query()->where('is_active', true)->orderBy('id')->lockForUpdate()->get();

            if (! $multi) {
                $blockers = $active->filter(static fn (StockLocation $l): bool => ! $l->is_default)->values()->all();
                if ($blockers !== []) {
                    throw BranchModeConflict::blocked($blockers);
                }
            }

            Setting::put(BranchMode::KEY, $multi, SettingGroup::Store, false, $userId);
        });
    }
}
