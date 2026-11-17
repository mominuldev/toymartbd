<?php

declare(strict_types=1);

use App\Domain\Ai\Advisor\Enums\ActionStatus;
use App\Domain\Ai\Advisor\Jobs\WriteScheduledBrief;
use App\Domain\Ai\Advisor\Models\AiBrief;
use App\Domain\Ai\Advisor\Models\AiBriefAction;
use App\Domain\Ai\Advisor\Services\AdvisorFacts;
use App\Domain\Ai\Advisor\Services\AdvisorWeek;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Models\AiRequest;
use App\Domain\Ai\Services\AiDrivers;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Analytics › Growth advisor (Figma 537:82561): facts without PII, the weekly brief with
 * server-built links, FigureGuard on every answer (rule 1), the AI problems, rate limits and
 * deciding a suggestion.
 */

function advisorConnect(): void
{
    Setting::put('ai.key.anthropic', 'sk-ant-test-0000000000000000', SettingGroup::Ai, true);
}

/** One order this week (Tue 17 Nov, Dhaka) for 2486.00 with a named customer and phone. */
function advisorOrder(Product $product): Order
{
    $customer = Customer::factory()->create(['name' => 'Rina Akter', 'phone' => '+8801711000222']);
    $order = Order::factory()->status(OrderStatus::Pending)->create([
        'customer_id' => $customer->id,
        'placed_at' => CarbonImmutable::parse('2026-11-17 04:00:00', 'UTC'),
        'subtotal' => '2416.00', 'shipping_charge' => '70.00', 'grand_total' => '2486.00', 'due_total' => '2486.00',
    ]);
    $variant = $product->variants()->firstOrFail();
    OrderItem::factory()->create([
        'order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $product->id,
        'product_name' => $product->name, 'sku' => $variant->sku,
        'unit_price' => '1208.00', 'quantity' => 2, 'line_total' => '2416.00', 'cost_price_snapshot' => '600.0000',
    ]);

    return $order;
}

/** @return array<string, mixed> */
function advisorBrief(int $productId, array $sentences = ['Orders placed this week: 1, worth ৳2,486.'], array $extraActions = []): array
{
    return [
        'sentences' => $sentences,
        'actions' => [
            ['priority' => 'high', 'kind' => 'restock', 'title' => 'Restock Kids Tricycle', 'why' => 'It sold 2 this week.', 'figures' => ['2 sold'], 'target_type' => 'product', 'target_id' => $productId],
            ['priority' => 'medium', 'kind' => 'verify_orders', 'title' => 'Check waiting orders', 'why' => 'Some orders wait for a call.', 'figures' => [], 'target_type' => 'verification_queue', 'target_id' => null],
            ['priority' => 'low', 'kind' => 'other', 'title' => 'Look at another product', 'why' => 'A made-up product id.', 'figures' => [], 'target_type' => 'product', 'target_id' => 424242],
            ...$extraActions,
        ],
    ];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 06:00:00', 'UTC')); // Wed, 12:00 Dhaka
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->fake = AiDrivers::fake();
    $this->product = Product::factory()->create(['name' => 'Kids Tricycle']);
    $this->manager = User::factory()->withRole(Role::Manager)->create()->refresh();
    $this->actingAs($this->manager, 'web');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('covers the shop week (Saturday–Friday) up to yesterday, against the same weekdays before', function (): void {
    $wed = AdvisorWeek::current('2026-11-18'); // today is never counted: it is not over
    expect([$wed->weekStart, $wed->from, $wed->to, $wed->compareFrom, $wed->compareTo, $wed->complete()])
        ->toBe(['2026-11-14', '2026-11-14', '2026-11-17', '2026-11-07', '2026-11-10', false]);

    $sat = AdvisorWeek::current('2026-11-21'); // the Saturday schedule: the whole week just ended
    expect([$sat->weekStart, $sat->to, $sat->compareFrom, $sat->compareTo, $sat->complete()])
        ->toBe(['2026-11-14', '2026-11-20', '2026-11-07', '2026-11-13', true]);

    $sun = AdvisorWeek::current('2026-11-22'); // Sunday: a one-day week so far
    expect([$sun->weekStart, $sun->to, $sun->days()])->toBe(['2026-11-21', '2026-11-21', 1]);
});

it('builds facts from the reports with figures only — no names, phones or addresses', function (): void {
    $order = advisorOrder($this->product);

    $facts = app(AdvisorFacts::class)->build(AdvisorWeek::current());
    $json = (string) json_encode($facts, JSON_UNESCAPED_UNICODE);

    expect($facts['sales']['this_week']['placed_orders'])->toBe(1)
        ->and($facts['sales']['this_week']['placed_value'])->toBe('2486.00')
        ->and($facts['best_sellers'][0])->toMatchArray(['product_id' => $this->product->id, 'name' => 'Kids Tricycle', 'units_sold' => 2])
        ->and($json)->not->toContain('Rina')
        ->and($json)->not->toContain('1711000222');
    if (is_string($order->order_number) && $order->order_number !== '') {
        expect($json)->not->toContain($order->order_number);
    }
    if (is_string($order->shipping_address_line ?? null)) {
        expect($json)->not->toContain($order->shipping_address_line);
    }
    expect(AdvisorFacts::targetIds($facts)['product'])->toBe([$this->product->id]);
});

it('hides product names from the facts when product details may not be shared', function (): void {
    advisorOrder($this->product);
    Setting::put('ai.share', ['figures' => true, 'products' => false, 'first_names' => true], SettingGroup::Ai);

    $facts = app(AdvisorFacts::class)->build(AdvisorWeek::current());

    expect($facts['best_sellers'][0]['name'])->toBe('Product #'.$this->product->id)
        ->and((string) json_encode($facts))->not->toContain('Kids Tricycle');
});

it('writes this week\'s brief and builds every link on the server', function (): void {
    advisorConnect();
    advisorOrder($this->product);
    $this->fake->respond(advisorBrief($this->product->id));

    $res = $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertOk();

    $res->assertJsonPath('brief.from', '2026-11-14')->assertJsonPath('brief.to', '2026-11-17')
        ->assertJsonPath('brief.sentences', ['Orders placed this week: 1, worth ৳2,486.'])
        ->assertJsonPath('brief.model', 'claude-opus-5-5')
        ->assertJsonPath('brief.scheduled', false)
        ->assertJsonPath('open_count', 3)
        ->assertJsonPath('actions.0.link', ['type' => 'product', 'path' => '/products/'.$this->product->id])
        ->assertJsonPath('actions.1.link', ['type' => 'verification_queue', 'path' => '/risk/queue'])
        // An id the facts never listed is not trusted: no link at all.
        ->assertJsonPath('actions.2.link', null);
    expect(AiBriefAction::query()->where('target_id', 424242)->exists())->toBeFalse()
        ->and($this->fake->sent)->toHaveCount(1)
        ->and($this->fake->sent[0]['prompt']->user)->toContain('"placed_value":"2486.00"')
        ->and($this->fake->sent[0]['prompt']->user)->not->toContain('Rina')
        ->and($this->fake->sent[0]['prompt']->schema['properties']['actions']['items']['additionalProperties'])->toBeFalse();

    $this->getJson('/api/v1/admin/analytics/advisor')->assertOk()->assertJsonPath('brief.week_start', '2026-11-14')->assertJsonCount(3, 'actions');
});

it('retries once on an invented figure, then drops the sentences and actions that still carry one', function (): void {
    advisorConnect();
    advisorOrder($this->product);
    $bad = advisorBrief($this->product->id, ['Sales were ৳99,999 — up 37%.', 'Orders placed this week: 1.'], [
        ['priority' => 'high', 'kind' => 'campaign', 'title' => 'Spend ৳5,000 on ads', 'why' => 'It will bring 300 orders.', 'figures' => ['300 orders'], 'target_type' => 'promotions', 'target_id' => null],
    ]);
    $this->fake->respond($bad, $bad);

    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertOk()
        ->assertJsonPath('brief.sentences', ['Orders placed this week: 1.'])
        ->assertJsonMissing(['title' => 'Spend ৳5,000 on ads']);

    expect($this->fake->sent)->toHaveCount(2)
        ->and($this->fake->sent[1]['prompt']->user)->toContain('not in FACTS: 99,999, 37, 5,000, 300')
        ->and(AiBrief::query()->sole()->sentences)->not->toContain('Sales were ৳99,999 — up 37%.');
});

it('refuses a brief whose every sentence carries an invented figure', function (): void {
    advisorConnect();
    $bad = advisorBrief($this->product->id, ['Sales were ৳99,999.']);
    $this->fake->respond($bad, $bad);

    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertStatus(503)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/ai-unavailable');
    expect(AiBrief::query()->exists())->toBeFalse();
});

it('keeps Western digits in a Bangla brief (rule 36)', function (): void {
    advisorConnect();
    advisorOrder($this->product);
    $this->fake->respond(advisorBrief($this->product->id, ['এই সপ্তাহে ১টি অর্ডার, মোট ৳২,৪৮৬।']));

    $this->postJson('/api/v1/admin/analytics/advisor/brief?locale=bn')->assertOk()
        ->assertJsonPath('brief.locale', 'bn')
        ->assertJsonPath('brief.sentences', ['এই সপ্তাহে 1টি অর্ডার, মোট ৳2,486।']);
    expect($this->fake->sent[0]['prompt']->user)->toContain('LANGUAGE: Bangla');
});

it('answers ai-not-configured and ai-cap-reached as problems', function (): void {
    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/ai-not-configured');

    advisorConnect();
    Setting::put('ai.cap_usd', '0.01', SettingGroup::Ai);
    AiRequest::query()->create(['feature' => 'advisor', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => '0.010000']);
    $this->postJson('/api/v1/admin/analytics/advisor/ask', ['question' => 'How did sales go?'])->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/ai-cap-reached');
});

it('refuses when report figures may not be shared, without calling the provider', function (): void {
    advisorConnect();
    Setting::put('ai.share', ['figures' => false, 'products' => true, 'first_names' => true], SettingGroup::Ai);

    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/ai-not-shared');
    $this->postJson('/api/v1/admin/analytics/advisor/ask', ['question' => 'How did sales go?'])->assertStatus(409);
    $this->getJson('/api/v1/admin/analytics/advisor')->assertOk()->assertJsonPath('ai.figures_shared', false)->assertJsonPath('brief', null);
    expect($this->fake->sent)->toBe([]);
});

it('rewrites the brief at most once every ten minutes', function (): void {
    advisorConnect();
    $this->fake->respond(advisorBrief($this->product->id, ['Quiet week so far.']), advisorBrief($this->product->id, ['Quiet week so far.']));

    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertOk();
    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertStatus(429)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/too-many-requests')->assertHeader('Retry-After');

    $this->travel(11)->minutes();
    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertOk();
    expect(AiBrief::query()->count())->toBe(1);
});

it('keeps decided suggestions when the week is rewritten and replaces the open ones', function (): void {
    advisorConnect();
    advisorOrder($this->product);
    $this->fake->respond(advisorBrief($this->product->id), advisorBrief($this->product->id));
    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertOk();
    $first = AiBriefAction::query()->orderBy('id')->firstOrFail();
    $this->postJson("/api/v1/admin/analytics/advisor/actions/{$first->id}/done")->assertOk()->assertJsonPath('status', 'done');

    RateLimiter::clear('ai-advisor:brief');
    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertOk()->assertJsonPath('open_count', 3)->assertJsonPath('done_this_month', 1);
    expect(AiBriefAction::query()->count())->toBe(4)->and($first->refresh()->status)->toBe(ActionStatus::Done);
});

it('marks a suggestion done or not useful once, recording who and when', function (): void {
    advisorConnect();
    $this->fake->respond(advisorBrief($this->product->id, ['Quiet week so far.']));
    $this->postJson('/api/v1/admin/analytics/advisor/brief')->assertOk();
    [$a, $b] = AiBriefAction::query()->orderBy('id')->get()->all();

    $this->postJson("/api/v1/admin/analytics/advisor/actions/{$a->id}/done")->assertOk()->assertJsonPath('status', 'done');
    $this->postJson("/api/v1/admin/analytics/advisor/actions/{$b->id}/dismiss")->assertOk()->assertJsonPath('status', 'dismissed');
    $this->postJson("/api/v1/admin/analytics/advisor/actions/{$a->id}/dismiss")->assertStatus(409);

    expect($a->refresh()->decided_by)->toBe($this->manager->id)->and($a->decided_at)->not->toBeNull();
    $this->getJson('/api/v1/admin/analytics/advisor')->assertOk()
        ->assertJsonPath('open_count', 1)->assertJsonPath('done_this_month', 1);
});

it('answers a question from the facts with sources', function (): void {
    advisorConnect();
    advisorOrder($this->product);
    $this->fake->respond(['answer_sentences' => ['Tuesday had 1 order worth ৳2,486.'], 'sources' => [['section' => 'sales_by_day', 'detail' => 'Tue'], ['section' => 'bogus', 'detail' => '']]]);

    $this->postJson('/api/v1/admin/analytics/advisor/ask', ['question' => 'Why were Tuesday’s orders low? Call 01711000222'])->assertOk()
        ->assertExactJson([
            'answer_sentences' => ['Tuesday had 1 order worth ৳2,486.'],
            'sources' => [['section' => 'sales_by_day', 'detail' => 'Tue']],
            'model' => 'claude-opus-5-5',
            'trimmed' => false,
        ]);
    expect($this->fake->sent[0]['prompt']->user)->toContain('QUESTION: Why were Tuesday’s orders low? Call [removed]');
});

it('retries an answer with an invented figure once, then trims it', function (): void {
    advisorConnect();
    advisorOrder($this->product);
    $bad = ['answer_sentences' => ['Tuesday had 1 order.', 'Usually you get 31.'], 'sources' => []];
    $this->fake->respond($bad, $bad);

    $this->postJson('/api/v1/admin/analytics/advisor/ask', ['question' => 'Why were Tuesday’s orders low?'])->assertOk()
        ->assertJsonPath('answer_sentences', ['Tuesday had 1 order.'])->assertJsonPath('trimmed', true);
    expect($this->fake->sent)->toHaveCount(2)->and($this->fake->sent[1]['prompt']->user)->toContain('not in FACTS: 31');
});

it('validates the question and limits each person to twenty an hour', function (): void {
    advisorConnect();
    $this->postJson('/api/v1/admin/analytics/advisor/ask', ['question' => str_repeat('a', 501)])->assertStatus(422);
    $this->postJson('/api/v1/admin/analytics/advisor/ask', [])->assertStatus(422);

    for ($i = 0; $i < 20; $i++) {
        RateLimiter::hit('ai-advisor:ask:'.$this->manager->id, 3600);
    }
    $this->postJson('/api/v1/admin/analytics/advisor/ask', ['question' => 'How did sales go?'])->assertStatus(429)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/too-many-requests');
    expect($this->fake->sent)->toBe([]);
});

it('writes the Saturday brief only when an AI key is set, and never throws', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 03:00:00', 'UTC')); // Sat 09:00 Dhaka
    app()->call([new WriteScheduledBrief, 'handle']);
    expect(AiBrief::query()->exists())->toBeFalse()->and($this->fake->sent)->toBe([]);

    advisorConnect();
    $this->fake->respond(new AiException(AiException::PROVIDER), advisorBrief($this->product->id, ['Quiet week.']));
    app()->call([new WriteScheduledBrief, 'handle']);
    expect(AiBrief::query()->exists())->toBeFalse();

    app()->call([new WriteScheduledBrief, 'handle']);
    $brief = AiBrief::query()->sole();
    expect($brief->week_start->toDateString())->toBe('2026-11-14')->and($brief->period_to->toDateString())->toBe('2026-11-20')
        ->and($brief->generated_by)->toBeNull();
});
