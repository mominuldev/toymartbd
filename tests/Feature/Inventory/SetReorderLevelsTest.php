<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\SetReorderLevels;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('sets reorder point and quantity without touching stock or the ledger', function (): void {
    $stocked = ProductVariant::factory()->create();
    $fresh = ProductVariant::factory()->create();
    DB::transaction(fn () => app(StockLedger::class)->recordOne($stocked->id, StockMovementType::OpeningBalance, 7));

    app(SetReorderLevels::class)->handle(StockLocation::defaultId(), [
        $stocked->id => ['point' => 4, 'quantity' => 10],
        $fresh->id => ['point' => 2, 'quantity' => 6],
    ]);

    expect(StockLevel::query()->where('variant_id', $stocked->id)->sole()->only(['on_hand', 'reorder_point', 'reorder_quantity']))
        ->toBe(['on_hand' => 7, 'reorder_point' => 4, 'reorder_quantity' => 10])
        ->and(StockLevel::query()->where('variant_id', $fresh->id)->sole()->only(['on_hand', 'reorder_point', 'reorder_quantity']))
        ->toBe(['on_hand' => 0, 'reorder_point' => 2, 'reorder_quantity' => 6])
        ->and(StockMovement::query()->count())->toBe(1);
});

it('refuses a negative reorder figure', function (): void {
    $variant = ProductVariant::factory()->create();

    app(SetReorderLevels::class)->handle(StockLocation::defaultId(), [$variant->id => ['point' => -1, 'quantity' => 0]]);
})->throws(InvalidArgumentException::class);
