<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\RecommendationEventType;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Catalog\Listeners\RecordRecommendationPurchasesOnOrderPlaced;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\RecommendationEvent;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * OrderPlaced → one `purchased` recommendation event per line with a recommendation_source (rule 25d).
 */

beforeEach(function (): void {
    $this->order = Order::factory()->create(['placed_at' => now()->subMinutes(5)->startOfSecond()]);
    [$this->fbt, $this->cross, $this->plain] = Product::factory()->count(3)->create()->all();
    $this->fbtLine = OrderItem::factory()->create(['order_id' => $this->order->id, 'variant_id' => $this->fbt->variants()->value('id'), 'product_id' => $this->fbt->id, 'recommendation_source' => 'pdp_fbt']);
    OrderItem::factory()->create(['order_id' => $this->order->id, 'variant_id' => $this->cross->variants()->value('id'), 'product_id' => $this->cross->id, 'recommendation_source' => 'cart_cross_sell']);
    OrderItem::factory()->create(['order_id' => $this->order->id, 'variant_id' => $this->plain->variants()->value('id'), 'product_id' => $this->plain->id, 'recommendation_source' => null]);
});

it('listens to OrderPlaced, queued on `index`', function (): void {
    Event::fake();
    Event::assertListening(OrderPlaced::class, RecordRecommendationPurchasesOnOrderPlaced::class);

});

it('is queued on `index` when an order is placed', function (): void {
    Queue::fake();
    OrderPlaced::dispatch($this->order->id, $this->order->customer_id);
    Queue::assertPushedOn('index', CallQueuedListener::class, static fn (CallQueuedListener $job): bool => $job->class === RecordRecommendationPurchasesOnOrderPlaced::class);
});

it('writes one purchased event per recommended line, with order, customer and variant', function (): void {
    OrderPlaced::dispatch($this->order->id, $this->order->customer_id);

    $events = RecommendationEvent::query()->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events->every(fn (RecommendationEvent $e): bool => $e->event === RecommendationEventType::Purchased
            && $e->order_id === $this->order->id
            && $e->customer_id === $this->order->customer_id
            && $e->source_product_id === null
            && $e->session_id === null
            && $e->occurred_at->toDateTimeString() === $this->order->placed_at->toDateTimeString()))->toBeTrue()
        ->and($events[0]->placement)->toBe(RecommendationPlacement::PdpFbt)
        ->and($events[0]->recommended_product_id)->toBe($this->fbt->id)
        ->and($events[0]->variant_id)->toBe($this->fbtLine->variant_id)
        ->and($events[1]->placement)->toBe(RecommendationPlacement::CartCrossSell)
        ->and($events[1]->recommended_product_id)->toBe($this->cross->id);
});

it('is idempotent: a replayed OrderPlaced writes nothing more', function (): void {
    $listener = app(RecordRecommendationPurchasesOnOrderPlaced::class);
    $listener->handle(new OrderPlaced($this->order->id, $this->order->customer_id));
    $listener->handle(new OrderPlaced($this->order->id, $this->order->customer_id));

    expect(RecommendationEvent::query()->count())->toBe(2);
});

it('writes nothing for an order with no recommended line', function (): void {
    $order = Order::factory()->create();
    OrderItem::factory()->create(['order_id' => $order->id]);

    app(RecordRecommendationPurchasesOnOrderPlaced::class)->handle(new OrderPlaced($order->id, $order->customer_id));

    expect(RecommendationEvent::query()->where('order_id', $order->id)->count())->toBe(0);
});
