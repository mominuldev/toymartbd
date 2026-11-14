<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Pos\Models\PosHeldSale;
use App\Models\Enums\Role;
use App\Models\User;
use Tests\Feature\Pos\PosKit;

/* "Hold sale" (Figma B · 33): park a cart on the drawer, serve the next customer, resume it once. */

beforeEach(function (): void {
    $this->branch = PosKit::branch();
    $this->cashier = PosKit::cashier();
    $this->session = PosKit::open($this->branch, $this->cashier);
    $this->toy = PosKit::variant($this->branch, 5, '1450.00');
});

it('holds a cart without reserving stock and resumes it once', function (): void {
    $held = $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds", [
        'lines' => PosKit::lines([$this->toy->id => 2]),
        'customer_phone' => '01819 552 310',
        'label' => 'Blue shirt',
    ])->assertCreated()
        ->assertJsonPath('label', 'Blue shirt')
        ->assertJsonPath('item_count', 2)
        ->assertJsonPath('lines.0.variant_id', $this->toy->id)
        ->assertJsonPath('customer_phone', '01819552310');

    // A parked cart does not starve the shelf.
    expect(StockLevel::query()->where('variant_id', $this->toy->id)->where('location_id', $this->branch->id)->sole()->reserved)->toBe(0);

    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds")->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/admin/pos/holds/{$held->json('id')}/resume")->assertOk()
        ->assertJsonPath('lines.0.quantity', 2)
        ->assertJsonPath('resumed_at', fn ($v) => is_string($v));

    // Gone from the list; a second resume is a 409.
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds")->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/admin/pos/holds/{$held->json('id')}/resume")->assertStatus(409);
    expect(PosHeldSale::query()->findOrFail($held->json('id'))->resumed_by)->toBe($this->cashier->id);
});

it('refuses an empty hold and an invalid phone', function (): void {
    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds", ['lines' => []])->assertStatus(422);
    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds", [
        'lines' => PosKit::lines([$this->toy->id => 1]), 'customer_phone' => '01112345678',
    ])->assertStatus(422)->assertJsonPath('errors.0.field', 'customer_phone');
});

it('keeps a colleague out of another cashier\'s held carts', function (): void {
    $held = $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds", ['lines' => PosKit::lines([$this->toy->id => 1])])->assertCreated();

    $colleague = User::factory()->withRole(Role::OrderOperator)->create()->refresh();
    auth()->forgetGuards();
    $this->actingAs($colleague, 'web');

    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds")->assertForbidden();
    $this->postJson("/api/v1/admin/pos/holds/{$held->json('id')}/resume")->assertForbidden();
    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/quote", ['lines' => []])->assertForbidden();
});
