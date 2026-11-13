<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Inventory\Contracts\OpenOrderReservations;
use App\Domain\Inventory\Enums\InventoryAlertKind;
use App\Domain\Inventory\Enums\InventoryAlertStatus;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Notification\Mail\StockDriftMail;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Inventory\InventoryOpsKit as Kit;

/*
 * The nightly reconciliation (DATABASE-DESIGN §7): detects an injected drift, records an
 * open alert, mails the managers, and NEVER corrects the level.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    Mail::fake();

    $this->manager = Kit::user(Role::Manager, actAs: false);
    $this->staff = Kit::user(Role::InventoryStaff, actAs: false);
    $this->puzzle = Kit::variant('Wooden Alphabet Puzzle', '310.00');
    $this->doll = Kit::variant('Fashion Doll', '1480.00', 'ফ্যাশন পুতুল');
    Kit::stock($this->puzzle->id, 12);
    Kit::stock($this->doll->id, 5);
});

/** A code path that bypassed the ledger: on_hand changed with no movement. */
function injectDrift(int $variantId, int $onHand): void
{
    DB::table('stock_levels')->where('variant_id', $variantId)->where('location_id', StockLocation::defaultId())->update(['on_hand' => $onHand]);
}

it('finds no drift when the ledger and the levels agree', function (): void {
    expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 0, 'new_or_changed' => 0])
        ->and(InventoryAlert::query()->whereIn('kind', ['ledger_drift', 'reserved_drift'])->count())->toBe(0);
    Mail::assertNothingSent();
});

it('detects an injected drift, raises an alert, mails managers and corrects nothing', function (): void {
    injectDrift($this->puzzle->id, 9);

    $result = app(ReconcileStockLevels::class)->handle();

    expect($result)->toMatchArray(['drift_count' => 1, 'new_or_changed' => 1, 'reserved_checked' => true]);
    $alert = InventoryAlert::query()->where('kind', InventoryAlertKind::LedgerDrift->value)->sole();
    expect($alert->variant_id)->toBe($this->puzzle->id)
        ->and($alert->expected)->toBe(12)
        ->and($alert->actual)->toBe(9)
        ->and($alert->difference())->toBe(-3)
        ->and($alert->status)->toBe(InventoryAlertStatus::Open)
        // Never auto-fixed: the level still holds the drifted figure.
        ->and(StockLevel::query()->where('variant_id', $this->puzzle->id)->value('on_hand'))->toBe(9);

    Mail::assertSent(StockDriftMail::class, fn (StockDriftMail $mail): bool => $mail->hasTo($this->manager->email)
        && ! $mail->hasTo($this->staff->email)
        && $mail->drifts[0]['sku'] === $this->puzzle->sku);
});

it('re-stamps a known drift instead of re-alerting, and re-alerts when it changes', function (): void {
    injectDrift($this->puzzle->id, 9);
    app(ReconcileStockLevels::class)->handle();
    Mail::fake();

    expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 1, 'new_or_changed' => 0]);
    Mail::assertNothingSent();

    injectDrift($this->puzzle->id, 7);
    expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['new_or_changed' => 1]);
    expect(InventoryAlert::query()->where('kind', 'ledger_drift')->sole()->actual)->toBe(7);
    Mail::assertSent(StockDriftMail::class);
});

it('detects ledger rows with no level row at all', function (): void {
    DB::table('stock_levels')->where('variant_id', $this->doll->id)->delete();

    app(ReconcileStockLevels::class)->handle();

    expect(InventoryAlert::query()->where('kind', 'ledger_drift')->where('variant_id', $this->doll->id)->sole())
        ->expected->toBe(5)->actual->toBe(0);
});

it('checks reserved against open orders when Ordering provides the quantities', function (): void {
    app()->instance(OpenOrderReservations::class, new class implements OpenOrderReservations
    {
        public function quantities(): array
        {
            return [StockLocation::defaultId() => [test()->puzzle->id => 2]];
        }
    });

    expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['reserved_checked' => true, 'drift_count' => 1]);
    expect(InventoryAlert::query()->where('kind', 'reserved_drift')->sole())->expected->toBe(2)->actual->toBe(0);
});

it('lets a manager acknowledge a drift alert, and the command reports failure while drift is open', function (): void {
    injectDrift($this->puzzle->id, 9);
    $this->artisan('toymart:inventory:reconcile')->assertExitCode(1);
    $alert = InventoryAlert::query()->where('kind', 'ledger_drift')->sole();

    Kit::actAs($this->staff);
    $this->postJson("/api/v1/admin/inventory/alerts/{$alert->id}/acknowledge", ['note' => 'x'])->assertForbidden();

    Kit::actAs($this->manager);
    $this->getJson('/api/v1/admin/inventory/alerts?filter[kind]=ledger_drift')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.difference', -3)->assertJsonPath('meta.counts.ledger_drift', 1);
    $this->postJson("/api/v1/admin/inventory/alerts/{$alert->id}/acknowledge", ['note' => 'Found a script that wrote stock directly; fixed by adjustment ADJ-2026-0042'])
        ->assertOk()->assertJsonPath('status', 'acknowledged')->assertJsonPath('acknowledged_by', $this->manager->name);
    $this->postJson("/api/v1/admin/inventory/alerts/{$alert->id}/acknowledge")->assertStatus(409);

    // Acknowledging changes no stock; the drift is still there, so tonight's run opens a new alert.
    expect(StockLevel::query()->where('variant_id', $this->puzzle->id)->value('on_hand'))->toBe(9);
    app(ReconcileStockLevels::class)->handle();
    expect(InventoryAlert::query()->where('kind', 'ledger_drift')->where('status', 'open')->count())->toBe(1);
});

it('passes the reconcile command when nothing drifted', function (): void {
    $this->artisan('toymart:inventory:reconcile')->assertExitCode(0);
    Kit::assertInvariants();
});
