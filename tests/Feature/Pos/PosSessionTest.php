<?php

declare(strict_types=1);

use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Pos\Enums\PosSessionStatus;
use App\Domain\Pos\Models\PosSession;
use App\Models\Enums\Role;
use App\Models\User;
use Tests\Feature\Pos\PosKit;

/*
 * Drawer sessions (Figma B · 33 header): open at a branch with a float, one per register and
 * one per cashier, close with a count → expected vs counted vs over/short, closed for good.
 */

beforeEach(function (): void {
    $this->branch = PosKit::branch();
    $this->cashier = PosKit::cashier();
});

it('opens a drawer at a branch with its float and shows it in the context', function (): void {
    $this->postJson('/api/v1/admin/pos/sessions', ['location_id' => $this->branch->id, 'register' => 'Register 1', 'opening_float' => '5000'])
        ->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('location.code', 'ctg')
        ->assertJsonPath('register', 'Register 1')
        ->assertJsonPath('cashier.id', $this->cashier->id)
        ->assertJsonPath('opening_float', '5000.00')
        ->assertJsonPath('totals.expected_cash', '5000.00')
        ->assertJsonPath('totals.sales_count', 0);

    $this->getJson('/api/v1/admin/pos/context')->assertOk()
        ->assertJsonPath('session.register', 'Register 1')
        ->assertJsonPath('tenders.0.method', 'cash')
        ->assertJsonPath('tenders.0.gives_change', true)
        ->assertJsonPath('tenders.1.method', 'bkash')
        ->assertJsonPath('tenders.1.requires_reference', true)
        ->assertJsonPath('whatsapp_receipt.enabled', false)
        ->assertJsonPath('whatsapp_receipt.reason', 'no_provider')
        ->assertJsonPath('can_manage', false);
});

it('allows one open drawer per register and one per cashier', function (): void {
    PosKit::open($this->branch, $this->cashier);

    // Same cashier, another register: refused.
    $this->postJson('/api/v1/admin/pos/sessions', ['location_id' => $this->branch->id, 'register' => 'Register 2', 'opening_float' => '0'])
        ->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');

    // Another cashier, same register (case and spaces ignored): refused.
    $other = User::factory()->withRole(Role::OrderOperator)->create()->refresh();
    auth()->forgetGuards();
    $this->actingAs($other, 'web');
    $this->postJson('/api/v1/admin/pos/sessions', ['location_id' => $this->branch->id, 'register' => ' register 1 ', 'opening_float' => '0'])
        ->assertStatus(409);

    // Another register at the same branch: fine.
    $this->postJson('/api/v1/admin/pos/sessions', ['location_id' => $this->branch->id, 'register' => 'Register 2', 'opening_float' => '0'])
        ->assertCreated();
});

it('refuses a pickup point and an inactive location', function (): void {
    $pickup = PosKit::branch('pkp', StockLocationType::Pickup);
    $this->postJson('/api/v1/admin/pos/sessions', ['location_id' => $pickup->id, 'register' => 'R1', 'opening_float' => '0'])->assertStatus(409);

    $this->branch->forceFill(['is_active' => false])->save();
    $this->postJson('/api/v1/admin/pos/sessions', ['location_id' => $this->branch->id, 'register' => 'R1', 'opening_float' => '0'])->assertStatus(409);
});

it('closes with expected = float + cash kept, records over/short, and frees the register', function (): void {
    $session = PosKit::open($this->branch, $this->cashier, '5000.00');
    $toy = PosKit::variant($this->branch, 10, '1850.00');
    $cube = PosKit::variant($this->branch, 10, '640.00');

    // ৳1,850 paid with ৳2,000 cash → ৳150 change, ৳1,850 kept.
    PosKit::charge($session, [$toy->id => 1], [['method' => 'cash', 'amount' => '2000']], '1850.00')->assertCreated();
    // ৳640 by bKash — not drawer cash.
    PosKit::charge($session, [$cube->id => 1], [['method' => 'bkash', 'amount' => '640', 'reference' => 'BK8XZ12']], '640.00')->assertCreated();

    $this->getJson("/api/v1/admin/pos/sessions/{$session->id}")->assertOk()
        ->assertJsonPath('totals.sales_count', 2)
        ->assertJsonPath('totals.sales_total', '2490.00')
        ->assertJsonPath('totals.expected_cash', '6850.00');

    $this->postJson("/api/v1/admin/pos/sessions/{$session->id}/close", ['counted_cash' => '6800.00', 'note' => 'One ৳50 note missing'])
        ->assertOk()
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('expected_cash', '6850.00')
        ->assertJsonPath('counted_cash', '6800.00')
        ->assertJsonPath('over_short', '-50.00')
        ->assertJsonPath('close_note', 'One ৳50 note missing');

    $closed = PosSession::query()->findOrFail($session->id);
    expect($closed->status)->toBe(PosSessionStatus::Closed)
        ->and($closed->closed_by)->toBe($this->cashier->id)
        ->and($closed->open_key)->toBeNull();

    // Closed for good: a second close, a sale and a hold are refused.
    $this->postJson("/api/v1/admin/pos/sessions/{$session->id}/close", ['counted_cash' => '6850'])->assertStatus(409);
    PosKit::charge($session, [$toy->id => 1], [['method' => 'cash', 'amount' => '1850']], '1850.00')->assertStatus(409);
    $this->postJson("/api/v1/admin/pos/sessions/{$session->id}/holds", ['lines' => PosKit::lines([$toy->id => 1])])->assertStatus(409);
    expect(fn () => $closed->forceFill(['close_note' => 'edited'])->save())->toThrow(App\Domain\Pos\Exceptions\PosRefused::class);

    // The register and the cashier can open a new drawer.
    $this->postJson('/api/v1/admin/pos/sessions', ['location_id' => $this->branch->id, 'register' => 'Register 1', 'opening_float' => '3000'])->assertCreated();
});

it('records an over count as positive', function (): void {
    $session = PosKit::open($this->branch, $this->cashier, '1000.00');

    $this->postJson("/api/v1/admin/pos/sessions/{$session->id}/close", ['counted_cash' => '1020.50'])
        ->assertOk()->assertJsonPath('over_short', '20.50');
});

it('lets a cashier close only their own drawer, and pos.manage close anyone\'s', function (): void {
    $session = PosKit::open($this->branch, $this->cashier);

    $colleague = User::factory()->withRole(Role::OrderOperator)->create()->refresh();
    auth()->forgetGuards();
    $this->actingAs($colleague, 'web');
    $this->postJson("/api/v1/admin/pos/sessions/{$session->id}/close", ['counted_cash' => '5000'])->assertForbidden();
    $this->getJson("/api/v1/admin/pos/sessions/{$session->id}")->assertForbidden();

    $manager = User::factory()->withRole(Role::Manager)->create()->refresh();
    auth()->forgetGuards();
    $this->actingAs($manager, 'web');
    $this->postJson("/api/v1/admin/pos/sessions/{$session->id}/close", ['counted_cash' => '5000'])->assertOk()
        ->assertJsonPath('over_short', '0.00');
    expect(PosSession::query()->findOrFail($session->id)->closed_by)->toBe($manager->id);
});
