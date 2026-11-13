<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Finance\Services\FinanceInvariant;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/** Shared helpers for the P5-INV inventory-operations tests. */
final class InventoryOpsKit
{
    public static function user(Role $role, bool $actAs = true): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->withRole($role)->create()->refresh();
        if ($actAs) {
            test()->actingAs($user, 'web');
        }

        return $user;
    }

    /** Switch the acting staff member (the sanctum guard caches the first request's user). */
    public static function actAs(User $user): void
    {
        auth()->forgetGuards();
        test()->actingAs($user, 'web');
    }

    public static function variant(string $name = 'Wooden Alphabet Puzzle', string $cost = '100.00', string $nameBn = 'কাঠের বর্ণমালা পাজল'): ProductVariant
    {
        $product = Product::factory()->withoutVariants()->create(['name' => $name, 'name_bn' => $nameBn, 'status' => ProductStatus::Active]);

        return ProductVariant::factory()->for($product)->create(['cost_price' => $cost]);
    }

    public static function stock(int $variantId, int $quantity, ?int $locationId = null): void
    {
        DB::transaction(fn () => app(StockLedger::class)->record([
            new StockMovementLine($variantId, StockMovementType::OpeningBalance, $quantity),
        ], $locationId));
    }

    public static function branch(string $code = 'ctg', string $name = 'Chattogram branch'): StockLocation
    {
        return StockLocation::query()->create(['code' => $code, 'name' => $name, 'name_bn' => 'চট্টগ্রাম শাখা', 'is_default' => false, 'is_active' => true]);
    }

    public static function onHand(int $variantId, ?int $locationId = null): int
    {
        return (int) StockLevel::query()->where('variant_id', $variantId)->where('location_id', $locationId ?? StockLocation::defaultId())->value('on_hand');
    }

    /** §7.1 for every level, and no ledger sum without a level. */
    public static function assertStockInvariant(): void
    {
        $sums = StockMovement::query()->groupBy('variant_id', 'location_id')
            ->select('variant_id', 'location_id', DB::raw('SUM(quantity) as total'))->get()
            ->mapWithKeys(fn ($r) => ["{$r->variant_id}:{$r->location_id}" => (int) $r->total])->all();

        foreach (StockLevel::all() as $level) {
            $key = "{$level->variant_id}:{$level->location_id}";
            expect($sums[$key] ?? 0)->toBe($level->on_hand, "ledger vs on_hand for {$key}");
            unset($sums[$key]);
        }
        expect(array_filter($sums))->toBe([]);
    }

    /** §7.2: order financials equal the ledger (no order is touched by these flows, so it must stay clean). */
    public static function assertFinanceInvariant(): void
    {
        if (class_exists(FinanceInvariant::class)) {
            expect(app(FinanceInvariant::class)->drift())->toBe([]);
        }
    }

    public static function assertInvariants(): void
    {
        self::assertStockInvariant();
        self::assertFinanceInvariant();
    }
}
