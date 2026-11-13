<?php

declare(strict_types=1);

use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Refund;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Returns\ReturnsKit;
use Tests\Support\RoleMatrix;

/*
 * "Owed back to a customer" (A19 cash card, P7 follow-up): refunds approved but not yet paid out,
 * as at the end of a Dhaka date. GET /finance/reports/refunds-owed, finance.view.
 */

const OWED = '/api/v1/admin/finance/reports/refunds-owed';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $variant = ReturnsKit::variant(100, '500.00', '300.00');
    $this->order = ReturnsKit::deliveredOrder([$variant->id => 10]);
});

function owedRefund(object $test, string $amount, RefundStatus $status, ?string $approvedAt, ?string $processedAt = null): Refund
{
    $refund = Refund::query()->create([
        'order_id' => $test->order->id, 'amount' => $amount, 'reason' => 'test', 'type' => 'partial', 'method' => 'cod', 'status' => $status,
    ]);
    $refund->forceFill([
        'approved_at' => $approvedAt === null ? null : CarbonImmutable::parse($approvedAt),
        'processed_at' => $processedAt === null ? null : CarbonImmutable::parse($processedAt),
    ])->save();

    return $refund;
}

it('totals approved, processing and failed refunds; never requested or rejected', function (): void {
    ReturnsKit::actingAs(Role::Accountant);
    owedRefund($this, '200.00', RefundStatus::Approved, '2026-11-10T04:00:00Z');
    owedRefund($this, '150.50', RefundStatus::Processing, '2026-11-09T04:00:00Z');
    owedRefund($this, '99.50', RefundStatus::Failed, '2026-11-08T04:00:00Z');
    owedRefund($this, '1000.00', RefundStatus::Requested, null);
    owedRefund($this, '1000.00', RefundStatus::Rejected, '2026-11-08T04:00:00Z');
    owedRefund($this, '1000.00', RefundStatus::Completed, '2026-11-07T04:00:00Z', '2026-11-08T04:00:00Z');

    $this->getJson(OWED.'?as_of=2026-11-12')
        ->assertOk()
        ->assertJsonPath('as_of', '2026-11-12')
        ->assertJsonPath('total', '450.00')
        ->assertJsonPath('count', 3)
        ->assertJsonPath('oldest_approved_at', '2026-11-08T04:00:00Z')
        ->assertJsonPath('refunds.0.amount', '99.50')
        ->assertJsonPath('refunds.0.status', 'failed')
        ->assertJsonPath('refunds.0.order_number', $this->order->number);
});

it('reads as at the end of a Dhaka date: approved later is not owed yet, paid later still is', function (): void {
    ReturnsKit::actingAs(Role::Accountant);
    // 2026-11-10 18:30Z is 00:30 on 11 Nov in Dhaka.
    owedRefund($this, '300.00', RefundStatus::Approved, '2026-11-10T18:30:00Z');
    // Approved on 9 Nov, paid out on 11 Nov (Dhaka): owed at the end of 10 Nov, not of 11 Nov.
    owedRefund($this, '120.00', RefundStatus::Completed, '2026-11-09T04:00:00Z', '2026-11-11T04:00:00Z');

    $this->getJson(OWED.'?as_of=2026-11-10')->assertOk()->assertJsonPath('total', '120.00')->assertJsonPath('count', 1);
    $this->getJson(OWED.'?as_of=2026-11-11')->assertOk()->assertJsonPath('total', '300.00')->assertJsonPath('count', 1);
});

it('answers zero when nothing is owed, and 400 for a bad date or an unknown parameter', function (): void {
    ReturnsKit::actingAs(Role::Accountant);

    $this->getJson(OWED)->assertOk()->assertJsonPath('total', '0.00')->assertJsonPath('count', 0)->assertJsonPath('refunds', []);
    $this->getJson(OWED.'?as_of=2026-13-45')->assertStatus(400);
    $this->getJson(OWED.'?as_of=yesterday')->assertStatus(400);
    $this->getJson(OWED.'?status=approved')->assertStatus(400);
});

it('guards GET /finance/reports/refunds-owed with finance.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(OWED));
})->with(RoleMatrix::dataset(Permission::FinanceView));
