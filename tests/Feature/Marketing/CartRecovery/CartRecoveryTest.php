<?php

declare(strict_types=1);

use App\Domain\Cart\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\DetectAbandonedCarts;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Jobs\SendCartRecoveryMessage;
use App\Domain\Notification\Models\CartRecovery;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Support\RoleMatrix;

/*
 * Abandoned-cart recovery (P7): detection after N minutes, consent and opt-out, one SMS per
 * cart (and per phone in the cooldown), cost recorded, link restores the cart with no PII
 * in the URL, the reminder stops once the order is placed, the report counts revenue from
 * delivered orders only, and the admin role matrix.
 */

final class RecoveryFakeSms implements SmsGateway
{
    /** @var list<array{to: string, body: string}> */
    public array $sent = [];

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $this->sent[] = ['to' => $e164Phone, 'body' => $body];
        $segments = App\Domain\Notification\Support\SmsSegments::count($body);

        return SmsResult::accepted('fake-'.count($this->sent), $segments, Money::of('0.26')->times($segments));
    }
}

function recoverySms(): RecoveryFakeSms
{
    $fake = new RecoveryFakeSms;
    app()->bind(App\Domain\Notification\Drivers\NullSmsGateway::class, fn () => $fake);
    config(['notifications.sms.driver' => 'null']);

    return $fake;
}

/** A guest cart with one ৳1000 line and a contact left on it. */
function abandonedCart(bool $consent = true, string $phone = '01712345678', string $locale = 'bn'): Cart
{
    $v = CartTestKit::variant('1000.00', 10);
    $token = CheckoutKit::cart([$v->id => 1]);
    test()->withToken(CartTestKit::storefrontToken())->putJson('/api/v1/storefront/cart/contact', [
        'phone' => $phone, 'marketing_consent' => $consent, 'locale' => $locale,
    ], [CartTestKit::HEADER => $token])->assertNoContent();

    return Cart::query()->where('token', $token)->sole();
}

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00', 'cart_recovery.after_minutes' => 60]);
    // Midday in Dhaka: outside the quiet hours.
    $this->travelTo(Carbon::parse('2026-12-01 12:00', 'Asia/Dhaka'));
});

it('waits N minutes, then sends one Bangla SMS with a restore link, recording segments and cost', function (): void {
    $sms = recoverySms();
    $cart = abandonedCart();

    $this->travel(59)->minutes();
    expect(app(DetectAbandonedCarts::class)->handle())->toBe(['messaged' => 0, 'skipped' => 0]);

    $this->travel(2)->minutes();
    expect(app(DetectAbandonedCarts::class)->handle())->toBe(['messaged' => 1, 'skipped' => 0])
        ->and($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['to'])->toBe('+8801712345678')
        ->and($sms->sent[0]['body'])->toContain('টয় মার্ট বিডি')->toContain('/bn/cart/restore/');

    $log = NotificationLog::query()->sole();
    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->notifiable_type)->toBe('cart_recovery')
        ->and($log->segments)->toBeGreaterThan(1) // Bangla UCS-2 with a URL
        ->and($log->cost->toDecimal())->toBe(Money::of('0.26')->times($log->segments)->toDecimal())
        ->and($log->recipient)->not->toContain('12345678')
        ->and(json_encode($log->payload))->not->toContain('restore')->not->toContain('+880');

    // The link carries an opaque token — not the cart token, no phone.
    preg_match('#/cart/restore/([A-Za-z0-9]+)#', $sms->sent[0]['body'], $m);
    expect($m[1])->not->toBe($cart->token)->and(strlen($m[1]))->toBe(24)
        ->and(CartRecovery::query()->sole()->restore_token_hash)->toBe(hash('sha256', $m[1]));
});

it('sends at most once per cart, however often detection runs', function (): void {
    $sms = recoverySms();
    abandonedCart();
    $this->travel(2)->hours();

    app(DetectAbandonedCarts::class)->handle();
    app(DetectAbandonedCarts::class)->handle();
    $this->travel(3)->hours();
    app(DetectAbandonedCarts::class)->handle();

    expect($sms->sent)->toHaveCount(1)->and(CartRecovery::query()->count())->toBe(1);
});

it('respects consent: no tick, no SMS; an explicit opt-out beats a tick', function (): void {
    $sms = recoverySms();
    abandonedCart(consent: false, phone: '01712345678');
    $optedOut = Customer::factory()->create(['phone' => '+8801812345678']);
    NotificationPreference::query()->create(['customer_id' => $optedOut->id, 'channel' => 'sms', 'category' => 'marketing', 'enabled' => false, 'source' => 'customer']);
    abandonedCart(consent: true, phone: '01812345678');
    $this->travel(2)->hours();

    expect(app(DetectAbandonedCarts::class)->handle())->toBe(['messaged' => 0, 'skipped' => 2])
        ->and($sms->sent)->toBe([])
        ->and(CartRecovery::query()->pluck('skip_reason')->map->value->sort()->values()->all())->toBe(['no_consent', 'opted_out']);
});

it('uses a signed-in customer’s explicit marketing opt-in', function (): void {
    $sms = recoverySms();
    $customer = Customer::factory()->create(['phone' => '+8801912345678', 'preferred_language' => 'en']);
    NotificationPreference::query()->create(['customer_id' => $customer->id, 'channel' => 'sms', 'category' => 'marketing', 'enabled' => true, 'source' => 'customer']);
    $v = CartTestKit::variant('500.00', 5);
    $cart = Cart::query()->where('token', CheckoutKit::cart([$v->id => 1]))->sole();
    $cart->forceFill(['customer_id' => $customer->id])->saveQuietly();
    $this->travel(2)->hours();

    app(DetectAbandonedCarts::class)->handle();

    expect($sms->sent)->toHaveCount(1)->and($sms->sent[0]['body'])->toStartWith('Toy Mart BD:')->not->toContain('/bn/');
});

it('stops when the order is placed before the reminder goes out', function (): void {
    Queue::fake([SendCartRecoveryMessage::class]);
    $sms = recoverySms();
    $cart = abandonedCart();
    $this->travel(2)->hours();
    app(DetectAbandonedCarts::class)->handle();
    Queue::assertPushed(SendCartRecoveryMessage::class);

    app(PlaceOrder::class)->handle(CheckoutKit::data($cart->token));
    (new SendCartRecoveryMessage(NotificationLog::query()->where('notifiable_type', 'cart_recovery')->sole()->id))->handle(app(App\Domain\Notification\Services\SmsDrivers::class), app(App\Domain\Notification\CartRecovery\RecoveryConsent::class));

    expect($sms->sent)->toBe([])
        ->and(NotificationLog::query()->where('notifiable_type', 'cart_recovery')->sole()->status)->toBe(NotificationStatus::Skipped)
        ->and(CartRecovery::query()->sole()->skip_reason?->value)->toBe('converted');
});

it('skips a cart whose phone already ordered, and a phone reminded within the cooldown', function (): void {
    $sms = recoverySms();
    abandonedCart(phone: '01712345678');
    $this->travel(2)->hours();
    app(DetectAbandonedCarts::class)->handle();

    // A second cart from the same phone, abandoned later: inside the 72 h cooldown.
    abandonedCart(phone: '01712345678');
    // A third phone already placed an order after changing its cart.
    $ordered = abandonedCart(phone: '01612345678');
    app(PlaceOrder::class)->handle(CheckoutKit::data(CheckoutKit::cart([CartTestKit::variant('300.00', 3)->id => 1]), '01612345678'));
    $this->travel(2)->hours();

    app(DetectAbandonedCarts::class)->handle();

    expect($sms->sent)->toHaveCount(1)
        ->and(CartRecovery::query()->where('cart_id', $ordered->id)->sole()->skip_reason?->value)->toBe('already_ordered')
        ->and(CartRecovery::query()->where('skip_reason', 'cooldown')->count())->toBe(1);
});

it('does not message during the Dhaka quiet hours', function (): void {
    $sms = recoverySms();
    $this->travelTo(Carbon::parse('2026-12-01 20:30', 'Asia/Dhaka'));
    abandonedCart();
    $this->travel(90)->minutes(); // 22:00 Dhaka

    expect(app(DetectAbandonedCarts::class)->handle())->toBe(['messaged' => 0, 'skipped' => 0])->and($sms->sent)->toBe([]);
});

it('restores the cart from the link, and marks it recovered when it becomes an order', function (): void {
    $sms = recoverySms();
    $cart = abandonedCart();
    $this->travel(2)->hours();
    app(DetectAbandonedCarts::class)->handle();
    preg_match('#/cart/restore/([A-Za-z0-9]+)#', $sms->sent[0]['body'], $m);
    $token = CartTestKit::storefrontToken();

    $this->withToken($token)->postJson('/api/v1/storefront/cart/restore', ['token' => $m[1]])
        ->assertOk()->assertJsonPath('token', $cart->token)->assertJsonPath('item_count', 1);
    $this->withToken($token)->postJson('/api/v1/storefront/cart/restore', ['token' => str_repeat('A', 24)])
        ->assertNotFound()->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');

    $order = app(PlaceOrder::class)->handle(CheckoutKit::data($cart->token));
    $recovery = CartRecovery::query()->sole();
    expect($recovery->restored_at)->not->toBeNull()->and($recovery->recovered_order_id)->toBe($order->id);

    // Once ordered, the link no longer resolves.
    $this->withToken($token)->postJson('/api/v1/storefront/cart/restore', ['token' => $m[1]])->assertNotFound();
});

it('reports recovered revenue from delivered orders only, with the SMS cost', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    recoverySms();
    $a = abandonedCart(phone: '01712345678');
    $b = abandonedCart(phone: '01812345678');
    abandonedCart(consent: false, phone: '01912345678');
    $this->travel(2)->hours();
    app(DetectAbandonedCarts::class)->handle();

    $delivered = app(PlaceOrder::class)->handle(CheckoutKit::data($a->token, '01712345678'));
    app(PlaceOrder::class)->handle(CheckoutKit::data($b->token, '01812345678'));
    DB::table('orders')->where('id', $delivered->id)->update(['status' => OrderStatus::Delivered->value]);

    auth()->forgetGuards();
    $this->flushHeaders()->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
    $summary = $this->getJson('/api/v1/admin/cart-recoveries/summary?from=2026-12-01&to=2026-12-01')->assertOk()->json();
    $cost = NotificationLog::query()->where('notifiable_type', 'cart_recovery')->get()->reduce(fn (Money $c, $l) => $c->plus($l->cost), Money::zero());

    expect($summary)->toMatchArray([
        'abandoned' => 3, 'messaged' => 2, 'sent' => 2, 'recovered' => 2, 'recovered_delivered' => 1,
        'revenue_recovered' => $delivered->grand_total->toDecimal(), 'cost' => $cost->toDecimal(), 'abandoned_value' => '3000.00',
    ])->and($summary['skipped']['no_consent'])->toBe(1);

    $list = $this->getJson('/api/v1/admin/cart-recoveries?filter[status]=recovered')->assertOk();
    expect($list->json('data'))->toHaveCount(2)
        ->and(json_encode($list->json()))->not->toContain('12345678');
    $this->getJson('/api/v1/admin/cart-recoveries?filter[nope]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/cart-recoveries/summary?from=2026-13-01')->assertStatus(400);
});

it('validates the contact endpoint', function (): void {
    $v = CartTestKit::variant('100.00', 1);
    $token = CheckoutKit::cart([$v->id => 1]);
    $this->withToken(CartTestKit::storefrontToken())->putJson('/api/v1/storefront/cart/contact', ['phone' => '01112345678', 'marketing_consent' => true], [CartTestKit::HEADER => $token])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'phone');
});

it('guards the abandoned-cart report with marketing.view', function (): void {
    RoleMatrix::assert($this, Permission::MarketingView, fn () => $this->getJson('/api/v1/admin/cart-recoveries'));
    RoleMatrix::assert($this, Permission::MarketingView, fn () => $this->getJson('/api/v1/admin/cart-recoveries/summary'));
});
