<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\Setting;

/**
 * Single shop or multiple branches (Store settings › Branches & warehouses › Branch setup,
 * owner request 2026-11-16).
 *
 * Saved as `store.multi_branch` (bool). Unset, the mode follows the data: more than one active
 * stock location means multi, so an install that already runs branches keeps them and a fresh
 * store starts as a single shop.
 *
 * The invariant it protects: single mode ⇔ at most one active location. UpdateBranchMode refuses
 * single while others are active; CreateStockLocation and UpdateStockLocation refuse to add or
 * switch on a second one while single. Every per-branch screen (transfers, location cards,
 * location pickers on purchases, receipts, adjustments and the till) already hides itself when
 * only one location is active, so the mode needs no other gate.
 */
final class BranchMode
{
    public const string KEY = 'store.multi_branch';

    public static function isMulti(): bool
    {
        $saved = self::saved();

        return $saved ?? self::activeCount() > 1;
    }

    /** The saved choice, or null while the owner has not chosen. */
    public static function saved(): ?bool
    {
        $raw = Setting::get(self::KEY);

        return is_bool($raw) ? $raw : null;
    }

    public static function activeCount(): int
    {
        return StockLocation::query()->where('is_active', true)->count();
    }

    /**
     * Units on hand per location (blockers show what has to be moved before going single).
     *
     * @param  list<int>  $locationIds
     * @return array<int, int>
     */
    public static function unitsAt(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }
        /** @var array<int, int> $sums */
        $sums = StockLevel::query()
            ->whereIn('location_id', $locationIds)
            ->groupBy('location_id')
            ->selectRaw('location_id, SUM(on_hand) AS units')
            ->pluck('units', 'location_id')
            ->map(static fn (mixed $v): int => (int) $v)
            ->all();

        return $sums;
    }

    /**
     * The active locations, default first then by id — what the admin's branch switcher offers.
     *
     * @return list<StockLocation>
     */
    public static function activeLocations(): array
    {
        return StockLocation::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get()->all();
    }
}
