<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Purchasing\Actions\CreatePurchase;
use App\Domain\Purchasing\Actions\PlacePurchaseOrder;
use App\Domain\Purchasing\Actions\ReceivePurchase;
use App\Domain\Purchasing\DTOs\PurchaseData;
use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\DTOs\ReceiptData;
use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Models\Supplier;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Builders for the purchasing tests. */
final class PurchasingKit
{
    /**
     * @param  list<array{0: ProductVariant, 1: int, 2: string, 3?: string}>  $lines  [variant, quantity, unit cost, discount]
     */
    public static function purchase(array $lines, ?Supplier $supplier = null, bool $ordered = true): Purchase
    {
        $supplier ??= Supplier::factory()->create(['name' => 'চট্টগ্রাম টয় ইমপোর্টস · Chittagong Toy Imports']);
        $purchase = app(CreatePurchase::class)->handle(new PurchaseData(
            supplierId: $supplier->id,
            lines: array_map(static fn (array $l): PurchaseLineData => new PurchaseLineData($l[0]->id, $l[1], $l[2], Money::of($l[3] ?? '0')), $lines),
            orderDate: CarbonImmutable::parse('2026-11-12'),
        ));

        return $ordered ? app(PlacePurchaseOrder::class)->handle($purchase) : $purchase;
    }

    /**
     * @param  array<int, int>  $quantities  purchase_item_id → units
     */
    public static function receive(Purchase $purchase, array $quantities, string $freight = '0', string $customs = '0', string $other = '0', LandedCostMethod $method = LandedCostMethod::Value, ?int $locationId = null, bool $costsToSupplier = true): PurchaseReceipt
    {
        return app(ReceivePurchase::class)->handle($purchase, self::receipt($quantities, $freight, $customs, $other, $method, $locationId, $costsToSupplier));
    }

    /** @param  array<int, int>  $quantities */
    public static function receipt(array $quantities, string $freight = '0', string $customs = '0', string $other = '0', LandedCostMethod $method = LandedCostMethod::Value, ?int $locationId = null, bool $costsToSupplier = true): ReceiptData
    {
        $lines = [];
        foreach ($quantities as $itemId => $quantity) {
            $lines[] = ['purchase_item_id' => $itemId, 'quantity' => $quantity];
        }

        return new ReceiptData($lines, Money::of($freight), Money::of($customs), Money::of($other), $method, $locationId, $costsToSupplier);
    }

    public static function open(int $variantId, int $quantity, ?int $locationId = null): void
    {
        DB::transaction(fn () => app(StockLedger::class)->record([
            new StockMovementLine($variantId, StockMovementType::OpeningBalance, $quantity),
        ], $locationId));
    }

    public static function level(int $variantId, ?int $locationId = null): StockLevel
    {
        return StockLevel::query()->where('variant_id', $variantId)
            ->when($locationId !== null, static fn ($q) => $q->where('location_id', $locationId))
            ->firstOrFail();
    }

    /** The level's avg_cost as a 4-dp string (SQLite hands DECIMAL back as a float). */
    public static function avg(int $variantId, ?int $locationId = null): string
    {
        return bcadd((string) self::level($variantId, $locationId)->avg_cost, '0', 4);
    }

    /** §7.1: movements sum to on_hand for every (variant, location). */
    public static function ledgerBalanced(): bool
    {
        foreach (StockLevel::all() as $level) {
            $sum = (int) StockMovement::query()->where('variant_id', $level->variant_id)->where('location_id', $level->location_id)->sum('quantity');
            if ($sum !== $level->on_hand) {
                return false;
            }
        }

        return true;
    }
}
