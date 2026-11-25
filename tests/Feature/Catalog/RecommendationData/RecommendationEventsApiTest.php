<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\RecommendationEventType;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Catalog\Exceptions\RecommendationLogViolation;
use App\Domain\Catalog\Jobs\RecordRecommendationEvents;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\RecommendationEvent;
use App\Domain\Customer\Models\Customer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Storefront\Account\AccountTestKit;

/*
 * POST /api/v1/storefront/recommendation-events (P10, rule 25d) — brief §4.
 */

beforeEach(function (): void {
    $this->token = CartTestKit::storefrontToken();
    $this->recoSource = Product::factory()->withoutVariants()->create(['name' => 'Wooden Stacker', 'name_bn' => 'কাঠের স্ট্যাকার']);
    $this->recoTarget = Product::factory()->withoutVariants()->create(['name' => 'Shape Sorter', 'name_bn' => 'আকৃতি সাজানো খেলনা']);
});

/** @param  array<string, mixed>  $overrides */
function recoBody(array $overrides = [], array $event = []): array
{
    return array_replace([
        'session_id' => 'rsid_abcdefghijklmnop',
        'events' => [array_replace([
            'placement' => 'pdp_cross_sell',
            'event' => 'impression',
            'source_product_id' => test()->recoSource->id,
            'recommended_product_id' => test()->recoTarget->id,
            'variant_id' => null,
        ], $event)],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function recoEvent(array $body, array $changes): array
{
    $body['events'][0] = array_replace($body['events'][0], $changes);

    return $body;
}

/** @param  array<string, mixed>  $body */
function postReco(array $body, ?string $token = null): TestResponse
{
    return test()->withToken($token ?? test()->token)->postJson('/api/v1/storefront/recommendation-events', $body);
}

it('accepts a batch with an empty 202 and queues one job on `index`', function (): void {
    Queue::fake();

    $response = postReco(recoBody())->assertStatus(202);
    expect($response->getContent())->toBe('');

    Queue::assertPushedOn('index', RecordRecommendationEvents::class, function (RecordRecommendationEvents $job): bool {
        return count($job->rows) === 1
            && $job->rows[0]['placement'] === 'pdp_cross_sell'
            && $job->rows[0]['source_product_id'] === test()->recoSource->id
            && $job->rows[0]['customer_id'] === null
            && $job->rows[0]['session_id'] === 'rsid_abcdefghijklmnop';
    });
    // Never inline: nothing is written by the request itself.
    expect(RecommendationEvent::query()->count())->toBe(0);
});

it('writes the rows when the job runs (UTC occurred_at, no customer on the server token)', function (): void {
    $this->travelTo(now('UTC')->setDate(2026, 11, 25)->setTime(18, 30));

    postReco(recoBody(['events' => [
        ['placement' => 'pdp_upsell', 'event' => 'impression', 'source_product_id' => $this->recoSource->id, 'recommended_product_id' => $this->recoTarget->id],
        ['placement' => 'pdp_fbt', 'event' => 'add_to_cart', 'source_product_id' => $this->recoSource->id, 'recommended_product_id' => $this->recoTarget->id, 'variant_id' => 77],
        ['placement' => 'cart_cross_sell', 'event' => 'click', 'source_product_id' => null, 'recommended_product_id' => $this->recoTarget->id],
    ]]))->assertStatus(202);

    $rows = RecommendationEvent::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->placement)->toBe(RecommendationPlacement::PdpUpsell)
        ->and($rows[1]->event)->toBe(RecommendationEventType::AddToCart)
        ->and($rows[1]->variant_id)->toBe(77)
        ->and($rows[2]->source_product_id)->toBeNull()
        ->and($rows[2]->customer_id)->toBeNull()
        ->and($rows[0]->occurred_at->format('Y-m-d H:i'))->toBe('2026-11-25 18:30');
});

it('records customer_id when the shopper\'s customer token is the bearer', function (): void {
    $customer = Customer::factory()->create();

    postReco(recoBody(), AccountTestKit::tokenFor($customer))->assertStatus(202);

    expect(RecommendationEvent::query()->sole()->customer_id)->toBe($customer->id);
});

it('refuses a request with no token', function (): void {
    $this->postJson('/api/v1/storefront/recommendation-events', recoBody())->assertStatus(401);
});

it('drops events for a product that does not exist, keeping the rest', function (): void {
    postReco(recoBody(['events' => [
        ['placement' => 'pdp_cross_sell', 'event' => 'impression', 'source_product_id' => $this->recoSource->id, 'recommended_product_id' => 999999],
        ['placement' => 'pdp_cross_sell', 'event' => 'impression', 'source_product_id' => $this->recoSource->id, 'recommended_product_id' => $this->recoTarget->id],
    ]]))->assertStatus(202);

    expect(RecommendationEvent::query()->pluck('recommended_product_id')->all())->toBe([$this->recoTarget->id]);
});

it('rejects `purchased` from a client — the server writes it', function (): void {
    postReco(recoBody(event: ['event' => 'purchased']))->assertStatus(422)
        ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'validation-error'))
        ->assertJsonPath('errors', fn (array $errors): bool => in_array('events.0.event', array_column($errors, 'field'), true));

    expect(RecommendationEvent::query()->count())->toBe(0);
});

it('validates the body', function (Closure $mutate, string $field): void {
    Queue::fake();

    postReco($mutate(recoBody()))->assertStatus(422)
        ->assertJsonPath('errors', fn (array $errors): bool => in_array($field, array_column($errors, 'field'), true));

    Queue::assertNothingPushed();
})->with([
    'no session' => [fn (array $b): array => array_diff_key($b, ['session_id' => true]), 'session_id'],
    'session too short' => [fn (array $b): array => ['session_id' => 'short'] + $b, 'session_id'],
    'session too long' => [fn (array $b): array => ['session_id' => str_repeat('a', 65)] + $b, 'session_id'],
    'session bad chars' => [fn (array $b): array => ['session_id' => 'abcdefghijklmnop qrs'] + $b, 'session_id'],
    'no events' => [fn (array $b): array => ['events' => []] + $b, 'events'],
    'events not a list' => [fn (array $b): array => ['events' => 'impression'] + $b, 'events'],
    'over 50 events' => [fn (array $b): array => ['events' => array_fill(0, 51, $b['events'][0])] + $b, 'events'],
    'unknown placement' => [fn (array $b): array => recoEvent($b, ['placement' => 'checkout_upsell']), 'events.0.placement'],
    'unknown event' => [fn (array $b): array => recoEvent($b, ['event' => 'view']), 'events.0.event'],
    'string recommended id' => [fn (array $b): array => recoEvent($b, ['recommended_product_id' => (string) $b['events'][0]['recommended_product_id']]), 'events.0.recommended_product_id'],
    'float recommended id' => [fn (array $b): array => recoEvent($b, ['recommended_product_id' => 1.5]), 'events.0.recommended_product_id'],
    'missing recommended id' => [fn (array $b): array => recoEvent($b, ['recommended_product_id' => null]), 'events.0.recommended_product_id'],
    'string source id' => [fn (array $b): array => recoEvent($b, ['source_product_id' => '12']), 'events.0.source_product_id'],
    'pdp placement without a source' => [fn (array $b): array => recoEvent($b, ['source_product_id' => null]), 'events.0.source_product_id'],
    'string variant id' => [fn (array $b): array => recoEvent($b, ['variant_id' => '5']), 'events.0.variant_id'],
    'zero variant id' => [fn (array $b): array => recoEvent($b, ['variant_id' => 0]), 'events.0.variant_id'],
    'unknown event key' => [fn (array $b): array => recoEvent($b, ['order_id' => 5]), 'events.0'],
]);

it('accepts a null source on the cart and confirmation rails', function (string $placement): void {
    Queue::fake();

    postReco(recoBody(event: ['placement' => $placement, 'source_product_id' => null]))->assertStatus(202);
})->with(['cart_cross_sell', 'confirmation_cross_sell']);

it('throttles per storefront session, not per shopper behind the same server', function (): void {
    Queue::fake();
    $limit = App\Providers\ApiServiceProvider::RECOMMENDATION_EVENTS_PER_SESSION_PER_MINUTE;

    foreach (range(1, $limit) as $i) {
        postReco(recoBody())->assertStatus(202);
    }
    postReco(recoBody())->assertStatus(429);
    // Another shopper session is unaffected.
    postReco(recoBody(['session_id' => 'rsid_zyxwvutsrqponmlk']))->assertStatus(202);
});

it('is append-only: an event cannot be updated or deleted', function (): void {
    $event = RecommendationEvent::factory()->create();

    expect(fn () => $event->update(['event' => RecommendationEventType::Click]))->toThrow(RecommendationLogViolation::class)
        ->and(fn () => $event->delete())->toThrow(RecommendationLogViolation::class);

    expect(RecommendationEvent::query()->sole()->event)->toBe(RecommendationEventType::Impression);
});
