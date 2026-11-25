<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\RebuildProductCopurchases;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCopurchase;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

/*
 * product_copurchases (P10, PRD X-04): distinct non-cancelled orders in the last 180 days that
 * held both products, both directions stored.
 */

/** @param  list<Product>  $products */
function copurchaseOrder(array $products, OrderStatus $status = OrderStatus::Delivered, ?CarbonImmutable $placedAt = null): Order
{
    $order = Order::factory()->status($status)->create(['placed_at' => $placedAt ?? now()->subDays(3)]);
    foreach ($products as $product) {
        OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $product->variants()->value('id'), 'product_id' => $product->id]);
    }

    return $order;
}

/** @return array<string, int> "a-b" → orders_count */
function copurchasePairs(): array
{
    return ProductCopurchase::query()->orderBy('product_id')->orderBy('other_product_id')->get()
        ->mapWithKeys(static fn (ProductCopurchase $p): array => [$p->product_id.'-'.$p->other_product_id => $p->orders_count])
        ->all();
}

beforeEach(function (): void {
    [$this->a, $this->b, $this->c, $this->d] = Product::factory()->count(4)->create()->all();
});

it('counts distinct orders per pair, in both directions', function (): void {
    copurchaseOrder([$this->a, $this->b]);
    copurchaseOrder([$this->a, $this->b, $this->c]);
    $latest = copurchaseOrder([$this->b, $this->a], placedAt: CarbonImmutable::now()->subHour()->startOfSecond());
    // The same product on two lines of one order still counts that order once.
    $twoLines = copurchaseOrder([$this->a, $this->c]);
    OrderItem::factory()->create(['order_id' => $twoLines->id, 'variant_id' => $this->a->variants()->value('id'), 'product_id' => $this->a->id]);

    $result = app(RebuildProductCopurchases::class)->handle();

    $a = $this->a->id;
    $b = $this->b->id;
    $c = $this->c->id;
    expect(copurchasePairs())->toEqual([
        "{$a}-{$b}" => 3, "{$a}-{$c}" => 2,
        "{$b}-{$a}" => 3, "{$b}-{$c}" => 1,
        "{$c}-{$a}" => 2, "{$c}-{$b}" => 1,
    ])->and($result['pairs'])->toBe(6);

    $ab = ProductCopurchase::query()->where('product_id', $a)->where('other_product_id', $b)->sole();
    expect($ab->last_ordered_at->toDateTimeString())->toBe($latest->placed_at->toDateTimeString())
        ->and($ab->computed_at)->not->toBeNull();
    // A product never pairs with itself.
    expect(ProductCopurchase::query()->whereColumn('product_id', 'other_product_id')->exists())->toBeFalse();
});

it('excludes cancelled orders but keeps every other status', function (): void {
    copurchaseOrder([$this->a, $this->b], OrderStatus::Cancelled);
    copurchaseOrder([$this->a, $this->c], OrderStatus::Pending);
    copurchaseOrder([$this->a, $this->d], OrderStatus::Returned);

    app(RebuildProductCopurchases::class)->handle();

    expect(ProductCopurchase::query()->where('product_id', $this->a->id)->pluck('other_product_id')->sort()->values()->all())
        ->toBe(collect([$this->c->id, $this->d->id])->sort()->values()->all());
});

it('only reads orders placed in the last 180 days', function (): void {
    copurchaseOrder([$this->a, $this->b], placedAt: CarbonImmutable::now()->subDays(181));
    copurchaseOrder([$this->a, $this->c], placedAt: CarbonImmutable::now()->subDays(179));

    app(RebuildProductCopurchases::class)->handle();

    expect(copurchasePairs())->toEqual([
        "{$this->a->id}-{$this->c->id}" => 1,
        "{$this->c->id}-{$this->a->id}" => 1,
    ]);
});

it('replaces the previous projection on every rebuild', function (): void {
    ProductCopurchase::factory()->create(['product_id' => $this->c->id, 'other_product_id' => $this->d->id, 'orders_count' => 40]);
    copurchaseOrder([$this->a, $this->b]);

    app(RebuildProductCopurchases::class)->handle();
    app(RebuildProductCopurchases::class)->handle();

    expect(copurchasePairs())->toEqual([
        "{$this->a->id}-{$this->b->id}" => 1,
        "{$this->b->id}-{$this->a->id}" => 1,
    ]);
});

it('runs from the command and is scheduled nightly at 03:30 Dhaka', function (): void {
    copurchaseOrder([$this->a, $this->b]);

    $this->artisan('toymart:catalog:copurchases')->assertSuccessful()->expectsOutputToContain('Rebuilt 2 co-purchase pair(s)');
    expect(ProductCopurchase::query()->count())->toBe(2);

    $event = collect(app(Schedule::class)->events())->first(static fn ($e): bool => str_contains((string) $e->command, 'toymart:catalog:copurchases'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->timezone)->toBe('Asia/Dhaka');
});

it('purges the products tag after a rebuild, so product pages pick up new bundles', function (): void {
    Illuminate\Support\Facades\Queue::fake();

    app(RebuildProductCopurchases::class)->handle();

    Illuminate\Support\Facades\Queue::assertPushedOn('index', App\Domain\Content\Jobs\RevalidateStorefrontTags::class, fn (App\Domain\Content\Jobs\RevalidateStorefrontTags $job): bool => $job->tags === ['products']);
});
