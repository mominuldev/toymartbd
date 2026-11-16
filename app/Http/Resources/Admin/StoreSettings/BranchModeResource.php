<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\StoreSettings;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\BranchMode;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Store settings › Branch setup: single shop or multiple branches.
 *
 * @property array{multi_branch: bool, saved: bool, active_locations: int, default_location: StockLocation|null, blockers: list<StockLocation>} $resource
 */
#[SchemaName('BranchMode')]
final class BranchModeResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** True: multiple branches (per-branch stock, transfers, a branch on purchases and the till). False: a single shop. */
            'multi_branch' => (bool) $r['multi_branch'],
            /** False while the owner has not chosen: the mode then follows the data (more than one active location = multi). */
            'saved' => (bool) $r['saved'],
            'active_locations' => (int) $r['active_locations'],
            /** @var array{id: int, name: string, name_bn: string|null}|null */
            'default_location' => $r['default_location'] === null ? null : self::location($r['default_location']),
            /**
             * Active locations other than the default: what stops switching to a single shop, with
             * the units still on hand there (to move with a transfer before switching it off).
             *
             * @var list<array{id: int, name: string, name_bn: string|null, units: int}>
             */
            'blockers' => self::blockers($r['blockers']),
        ];
    }

    /**
     * @param  list<StockLocation>  $locations
     * @return list<array{id: int, name: string, name_bn: string|null, units: int}>
     */
    public static function blockers(array $locations): array
    {
        $units = BranchMode::unitsAt(array_map(static fn (StockLocation $l): int => $l->id, $locations));

        return array_map(static fn (StockLocation $l): array => self::location($l) + ['units' => $units[$l->id] ?? 0], $locations);
    }

    /** @return array{id: int, name: string, name_bn: string|null} */
    public static function location(StockLocation $l): array
    {
        return ['id' => $l->id, 'name' => $l->name, 'name_bn' => $l->name_bn];
    }
}
