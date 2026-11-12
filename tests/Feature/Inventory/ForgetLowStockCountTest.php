<?php

declare(strict_types=1);

use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Listeners\ForgetLowStockCount;
use App\Domain\Inventory\Models\StockLocation;
use Illuminate\Support\Facades\Cache;

/*
 * The nav badge cache is cleared after every committed stock change — checkout included.
 * When the cache store is down (CI's admin E2E job had no Redis), the listener used to
 * throw, turning an already-placed order into a 500. It must swallow and report instead.
 */

it('does not let a cache failure escape after a stock change', function (): void {
    // One branch, so forgetLowCounts() actually reaches the cache.
    StockLocation::query()->create(['code' => 'ctg', 'name' => 'Chattogram · Agrabad', 'is_active' => true]);
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('Connection refused'));

    // A plain call: any exception escaping the listener fails the test.
    (new ForgetLowStockCount)->handle(new StockLevelChanged(1, 1));

    expect(true)->toBeTrue();
});
