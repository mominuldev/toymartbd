<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Ordering\Models\Order;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Services\PosDiscountSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Orders\Support;
use Tests\Feature\Pos\PosKit;

/*
 * The cashier's discount (owner decision 2026-11-15): any POS user, at most 10% of the goods
 * after promotions and the coupon (a setting), with a reason. Priced on the server, spread over
 * the order items, booked as discount_given, and kept with the sale.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->branch = PosKit::branch();
    $this->cashier = PosKit::cashier();
    $this->session = PosKit::open($this->branch, $this->cashier);
    $this->brick = PosKit::variant($this->branch, 10, '1850.00', '1100.00');
    $this->cube = PosKit::variant($this->branch, 12, '320.00', '150.00');
});

function discountQuote(object $test, array $discount): Illuminate\Testing\TestResponse
{
    return $test->postJson("/api/v1/admin/pos/sessions/{$test->session->id}/quote", [
        'lines' => PosKit::lines([$test->brick->id => 1, $test->cube->id => 2]),
        'discount' => $discount,
    ]);
}

it('quotes a percent discount as a row with its reason, off the total, without a customer phone', function (): void {
    // Goods 1850 + 640 = 2490; 10% = 249.
    discountQuote($this, ['type' => 'percent', 'value' => '10', 'reason' => 'regular_customer'])->assertOk()
        ->assertJsonPath('discount.amount', '249.00')
        ->assertJsonPath('discount.max_percent', '10')
        ->assertJsonPath('discount.reason', 'regular_customer')
        ->assertJsonPath('discount_total', '249.00')
        ->assertJsonPath('grand_total', '2241.00')
        ->assertJsonPath('rows.1', ['type' => 'manual', 'label' => 'regular_customer', 'label_bn' => null, 'amount' => '-249.00'])
        ->assertJsonPath('blockers', [])
        ->assertJsonPath('lines.0.discount', '185.00')
        ->assertJsonPath('lines.1.discount', '64.00');
});

it('quotes a flat taka discount', function (): void {
    discountQuote($this, ['type' => 'amount', 'value' => '90', 'reason' => 'damaged_packaging'])->assertOk()
        ->assertJsonPath('discount.amount', '90.00')
        ->assertJsonPath('grand_total', '2400.00');
});

it('blocks a discount over the cap and leaves the total alone', function (): void {
    discountQuote($this, ['type' => 'percent', 'value' => '15', 'reason' => 'other'])->assertOk()
        ->assertJsonPath('discount.amount', '0.00')
        ->assertJsonPath('grand_total', '2490.00')
        ->assertJsonPath('chargeable', false)
        ->assertJsonPath('blockers.0', ['field' => 'discount', 'message' => 'A discount can be at most 10% of this sale (৳249.00).']);
    discountQuote($this, ['type' => 'amount', 'value' => '249.01', 'reason' => 'other'])->assertOk()->assertJsonPath('blockers.0.field', 'discount');

    // The owner raises the cap.
    Setting::put(PosDiscountSettings::KEY, '20', SettingGroup::Checkout);
    discountQuote($this, ['type' => 'percent', 'value' => '15', 'reason' => 'other'])->assertOk()
        ->assertJsonPath('discount.max_percent', '20')
        ->assertJsonPath('grand_total', '2116.50');
});

it('needs a reason, a known type and a sane value', function (): void {
    discountQuote($this, ['type' => 'percent', 'value' => '5'])->assertStatus(422)->assertJsonPath('errors.0.field', 'discount.reason');
    discountQuote($this, ['type' => 'percent', 'value' => '5', 'reason' => 'my cousin'])->assertStatus(422)->assertJsonPath('errors.0.field', 'discount.reason');
    discountQuote($this, ['type' => 'half', 'value' => '5', 'reason' => 'other'])->assertStatus(422)->assertJsonPath('errors.0.field', 'discount.type');
    discountQuote($this, ['type' => 'amount', 'value' => '-5', 'reason' => 'other'])->assertStatus(422)->assertJsonPath('errors.0.field', 'discount.value');
    discountQuote($this, ['type' => 'percent', 'value' => '101', 'reason' => 'other'])->assertStatus(422)->assertJsonPath('errors.0.field', 'discount.value');
});

it('charges with the discount: order and items carry it, the ledger books it, the sale keeps why', function (): void {
    $discount = ['type' => 'percent', 'value' => '10', 'reason' => 'bulk_purchase'];
    $sale = PosKit::charge($this->session, [$this->brick->id => 1, $this->cube->id => 2], [['method' => 'cash', 'amount' => '2500']], '2241.00', ['discount' => $discount])
        ->assertCreated()
        ->assertJsonPath('grand_total', '2241.00')
        ->assertJsonPath('change_due', '259.00');

    $order = Order::query()->with('items')->findOrFail($sale->json('order_id'));
    expect((string) $order->manual_discount)->toBe('249.00')
        ->and((string) $order->discount_total)->toBe('249.00')
        ->and((string) $order->grand_total)->toBe('2241.00')
        ->and($order->items->reduce(fn (string $c, $i): string => bcadd($c, (string) $i->discount, 2), '0'))->toBe('249.00');

    $posSale = PosSale::query()->findOrFail($sale->json('id'));
    expect((string) $posSale->manual_discount)->toBe('249.00')
        ->and($posSale->discount_type)->toBe('percent')
        ->and((string) $posSale->discount_value)->toBe('10.00')
        ->and($posSale->discount_reason)->toBe('bulk_purchase')
        ->and($posSale->cashier_id)->toBe($this->cashier->id);

    $given = FinancialTransaction::query()->where('source_type', 'order')->where('source_id', $order->id)->where('type', LedgerEntryType::DiscountGiven)->sole();
    expect((string) $given->amount)->toBe('249.00');

    $this->getJson("/api/v1/admin/pos/sales/{$posSale->id}/receipt")->assertOk()
        ->assertJsonPath('manual_discount', '249.00')
        ->assertJsonPath('discount_reason', 'bulk_purchase');

    Support::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
});

it('refuses a charge whose discount is over the cap', function (): void {
    PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '2000']], '1572.50', ['discount' => ['type' => 'percent', 'value' => '15', 'reason' => 'other']])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'discount');
    expect(Order::query()->count())->toBe(0);
});
