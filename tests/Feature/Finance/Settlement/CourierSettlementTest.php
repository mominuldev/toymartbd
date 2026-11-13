<?php

declare(strict_types=1);

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Events\CourierSettlementReconciled;
use App\Domain\Finance\Listeners\Settlement\BookCourierSettlement;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Services\Receivables;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliverySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Finance\FinanceKit;
use Tests\Support\RoleMatrix;

/*
 * COD settlement reconciliation (DATABASE-DESIGN §4.5): match, flag variances for a person,
 * book cod_received (gross) + unbooked courier costs on confirm, idempotent per line.
 */

beforeEach(function (): void {
    $this->seed(DeliverySeeder::class);
    Storage::fake(App\Domain\Catalog\Services\MediaUrls::originalsDisk());
});

/** A delivered COD order (grand total 1170) with a delivered Steadfast shipment. */
function deliveredShipment(string $consignment, string $charge = '130.00', ?string $codFee = '11.70', string $status = 'delivered'): array
{
    $order = FinanceKit::shippedOrder();
    if ($status === 'delivered') {
        FinanceKit::deliver($order);
    }
    $provider = (int) DB::table('courier_providers')->where('code', 'steadfast')->value('id');
    $id = DB::table('shipments')->insertGetId([
        'order_id' => $order->id, 'provider_id' => $provider, 'number' => 'SHP-'.Str::random(8),
        'consignment_id' => $consignment, 'status' => $status, 'cod_amount' => '1170.00', 'declared_value' => '1170.00',
        'charge' => $charge, 'cod_fee' => $codFee, 'delivered_at' => $status === 'delivered' ? now() : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$order->refresh(), $id];
}

function importSettlement(array $body, string $key): Illuminate\Testing\TestResponse
{
    return test()->post('/api/v1/admin/finance/settlements', $body + ['provider' => 'steadfast', 'received_at' => now('Asia/Dhaka')->toDateString()], ['Idempotency-Key' => 'settle-key-'.$key, 'Accept' => 'application/json']);
}

function ledgerRows(string $type): Illuminate\Support\Collection
{
    return DB::table('financial_transactions')->where('type', $type)->get();
}

it('matches a statement to shipments, flags a deliberate variance, books nothing until confirmed', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    [$clean] = deliveredShipment('SF1001');
    [$short] = deliveredShipment('SF1002');
    [$inTransit] = deliveredShipment('SF1003', status: 'in_transit');

    $csv = "Consignment ID,Collected Amount,Delivery Charge,COD Charge\n"
        ."SF1001,\"1,170.00\",130.00,11.70\n"
        ."SF1002,1000.00,130.00,11.70\n"     // deliberate: courier paid 170 short
        ."SF9999,500.00,70.00,5.00\n"        // not ours
        ."SF1003,1170.00,130.00,11.70\n";    // not delivered on our side
    $response = importSettlement(['reference' => 'SFC-23618228', 'file' => UploadedFile::fake()->createWithContent('payment.csv', $csv)], 'stl-1')->assertCreated();

    $items = collect($response->json('items'))->keyBy('consignment_id');
    expect($response->json('status'))->toBe('disputed')
        ->and($response->json('totals.cod_collected'))->toBe('3840.00')
        ->and($items['SF1001']['variance_type'])->toBe('none')
        ->and($items['SF1001']['expected_cod'])->toBe('1170.00')
        ->and($items['SF1001']['variance'])->toBe('0.00')
        ->and($items['SF1001']['order_number'])->toBe($clean->number)
        ->and($items['SF1002']['variance_type'])->toBe('short')
        ->and($items['SF1002']['variance'])->toBe('-170.00')
        ->and($items['SF9999']['variance_type'])->toBe('unknown_consignment')
        ->and($items['SF1003']['variance_type'])->toBe('not_delivered')
        ->and($response->json('unresolved_count'))->toBe(3);
    expect(ledgerRows('cod_received'))->toHaveCount(0);

    // Confirm is refused while a variance has no decision; an unknown line cannot be accepted.
    $id = $response->json('id');
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/confirm", [], ['Idempotency-Key' => 'settle-key-c-1'])->assertStatus(409);
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/items/{$items['SF9999']['id']}/review", ['decision' => 'accepted'])->assertStatus(409);
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/items/{$items['SF9999']['id']}/review", ['decision' => 'excluded'])->assertStatus(422);
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/items/{$items['SF9999']['id']}/review", ['decision' => 'excluded', 'note' => 'অন্য ব্যবসায়ীর পার্সেল'])->assertOk();
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/items/{$items['SF1003']['id']}/review", ['decision' => 'excluded', 'note' => 'ট্রানজিটে'])->assertOk();
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/items/{$items['SF1002']['id']}/review", ['decision' => 'accepted', 'note' => 'courier dispute raised'])->assertOk();

    $this->postJson("/api/v1/admin/finance/settlements/{$id}/confirm", [], ['Idempotency-Key' => 'settle-key-c-2'])
        ->assertOk()->assertJsonPath('status', 'reconciled');

    // Gross COD as cash (not revenue) per booked line; charges and COD fee booked once per shipment.
    $cod = ledgerRows('cod_received');
    expect($cod)->toHaveCount(2)
        ->and($cod->pluck('amount')->map(fn ($a) => Money::of((string) $a)->toDecimal())->sort()->values()->all())->toBe(['1000.00', '1170.00'])
        ->and($cod->every(fn ($r) => (bool) $r->is_cash_movement && ! (bool) $r->is_recognised_revenue))->toBeTrue()
        ->and(ledgerRows('courier_charge'))->toHaveCount(2)
        ->and(ledgerRows('courier_cod_fee'))->toHaveCount(2);
    expect(app(Receivables::class)->forOrder($clean->id)->toDecimal())->toBe('0.00')
        ->and(app(Receivables::class)->forOrder($short->id)->toDecimal())->toBe('170.00')
        ->and(app(Receivables::class)->forOrder($inTransit->id)->toDecimal())->toBe('0.00');
    FinanceKit::assertFinanceInvariant();

    // A second confirm is a conflict; a replayed event writes nothing.
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/confirm", [], ['Idempotency-Key' => 'settle-key-c-3'])->assertStatus(409);
    app(BookCourierSettlement::class)->handle(new CourierSettlementReconciled($id));
    app(BookCourierSettlement::class)->handle(new CourierSettlementReconciled($id));
    expect(ledgerRows('cod_received'))->toHaveCount(2)->and(ledgerRows('courier_cod_fee'))->toHaveCount(2);
    FinanceKit::assertFinanceInvariant();
});

it('refuses to import the same courier reference twice, and a settled shipment is flagged next time', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    deliveredShipment('SF2001');
    $lines = [['consignment_id' => 'SF2001', 'cod_amount' => '1170.00', 'delivery_charge' => '130.00', 'cod_fee' => '11.70']];

    $id = importSettlement(['reference' => 'SFC-1', 'lines' => $lines], 'm-1')->assertCreated()->json('id');
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/confirm", [], ['Idempotency-Key' => 'settle-key-m-c'])->assertOk();

    importSettlement(['reference' => 'SFC-1', 'lines' => $lines], 'm-2')->assertStatus(409);
    $again = importSettlement(['reference' => 'SFC-2', 'lines' => $lines], 'm-3')->assertCreated();
    expect($again->json('items.0.variance_type'))->toBe('already_settled')
        ->and(ledgerRows('cod_received'))->toHaveCount(1);
});

it('does not re-book a courier charge or COD fee the courier listeners already wrote', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    [, $shipment] = deliveredShipment('SF3001');
    app(Ledger::class)->record(
        new LedgerEntry(LedgerEntryType::CourierCharge, Direction::Out, Money::of('130.00'), CarbonImmutable::now('Asia/Dhaka'), 'shipment', $shipment, true, true),
        new LedgerEntry(LedgerEntryType::CourierCodFee, Direction::Out, Money::of('11.70'), CarbonImmutable::now('Asia/Dhaka'), 'shipment', $shipment, true, true),
    );

    $id = importSettlement(['reference' => 'SFC-3', 'lines' => [['consignment_id' => 'SF3001', 'cod_amount' => '1170.00', 'delivery_charge' => '130.00', 'cod_fee' => '11.70']]], 'd-1')->json('id');
    $this->postJson("/api/v1/admin/finance/settlements/{$id}/confirm", [], ['Idempotency-Key' => 'settle-key-d-c'])->assertOk();

    expect(ledgerRows('courier_charge'))->toHaveCount(1)->and(ledgerRows('courier_cod_fee'))->toHaveCount(1)
        ->and(ledgerRows('cod_received'))->toHaveCount(1);
    FinanceKit::assertFinanceInvariant();
});

it('flags a delivered parcel the courier left out of the period as missing', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    deliveredShipment('SF4001');
    [$missing] = deliveredShipment('SF4002');
    $today = now('Asia/Dhaka')->toDateString();

    $response = importSettlement([
        'reference' => 'SFC-4', 'period_start' => $today, 'period_end' => $today,
        'lines' => [['consignment_id' => 'SF4001', 'cod_amount' => '1170.00', 'delivery_charge' => '130.00', 'cod_fee' => '11.70']],
    ], 'p-1')->assertCreated();

    $row = collect($response->json('items'))->firstWhere('consignment_id', 'SF4002');
    expect($row['variance_type'])->toBe('missing')->and($row['order_id'])->toBe($missing->id)
        ->and($row['expected_cod'])->toBe('1170.00')->and($row['line_no'])->toBeNull();
});

it('flags a line above the outstanding receivable (a manual COD receipt was already recorded)', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    [$order] = deliveredShipment('SF5001');
    $this->postJson("/api/v1/admin/finance/orders/{$order->id}/cod-receipts", ['amount' => '1170.00', 'received_on' => now('Asia/Dhaka')->toDateString()], ['Idempotency-Key' => 'settle-key-man-1'])->assertCreated();

    $response = importSettlement(['reference' => 'SFC-5', 'lines' => [['consignment_id' => 'SF5001', 'cod_amount' => '1170.00']]], 'r-1');
    expect($response->json('items.0.variance_type'))->toBe('exceeds_receivable');
});

it('refuses a statement without a consignment column', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    importSettlement(['reference' => 'X', 'file' => UploadedFile::fake()->createWithContent('p.csv', "amount\n100\n")], 'f-1')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'file');
    expect(CourierSettlement::query()->count())->toBe(0);
});

it('guards the settlement endpoints by role', function (): void {
    deliveredShipment('SF6001');
    $n = 0;
    RoleMatrix::assert($this, Permission::FinanceView, fn () => $this->getJson('/api/v1/admin/finance/settlements'));
    RoleMatrix::assert($this, Permission::FinanceReconcileCod, function () use (&$n) {
        $n++;

        return importSettlement(['reference' => 'R-'.$n, 'lines' => [['consignment_id' => 'SF6001', 'cod_amount' => '1170.00']]], 'role-'.$n);
    }, 201);
    $settlement = CourierSettlement::query()->firstOrFail();
    RoleMatrix::assert($this, Permission::FinanceView, fn () => $this->getJson('/api/v1/admin/finance/settlements/'.$settlement->id));
    RoleMatrix::assert($this, Permission::FinanceReconcileCod, function () use (&$n) {
        $n++;
        $s = importSettlement(['reference' => 'C-'.$n, 'lines' => [['consignment_id' => 'NOPE-'.$n, 'cod_amount' => '1.00']]], 'imp-'.$n);
        $id = CourierSettlement::query()->latest('id')->value('id');
        $item = DB::table('courier_settlement_items')->where('settlement_id', $id)->value('id');

        return $this->postJson("/api/v1/admin/finance/settlements/{$id}/items/{$item}/review", ['decision' => 'excluded', 'note' => 'x']);
    });
});
