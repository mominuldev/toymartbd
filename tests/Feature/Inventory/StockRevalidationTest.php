<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Listeners\RevalidateStockPages;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Services\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * StockLevelChanged → storefront revalidation of the product page and the lists
 * (in_stock on cards, the availability facet).
 */

it('wires StockLevelChanged to the listener', function (): void {
    Event::fake([StockLevelChanged::class]);

    Event::assertListening(StockLevelChanged::class, RevalidateStockPages::class);
});

it('queues products + product:{slug} when stock moves or is reserved', function (): void {
    Queue::fake();
    $product = Product::factory()->create(['slug' => 'magnetic-tiles']);
    $variant = ProductVariant::factory()->for($product)->create();

    DB::transaction(fn () => app(StockLedger::class)->recordOne($variant->id, StockMovementType::OpeningBalance, 3));
    app(ReserveStock::class)->handle([$variant->id => 1]);

    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['products', 'product:magnetic-tiles']);
});
