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
        'customer_name' => ' Rubina Akter ',
        'label' => 'Blue shirt',
    ])->assertCreated()
        ->assertJsonPath('label', 'Blue shirt')
        ->assertJsonPath('customer_name', 'Rubina Akter')
        ->assertJsonPath('item_count', 2)
        ->assertJsonPath('lines.0.variant_id', $this->toy->id)
        ->assertJsonPath('customer_phone', '01819552310');

    // A parked cart does not starve the shelf.
    expect(StockLevel::query()->where('variant_id', $this->toy->id)->where('location_id', $this->branch->id)->sole()->reserved)->toBe(0);

    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds")->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/admin/pos/holds/{$held->json('id')}/resume")->assertOk()
        ->assertJsonPath('lines.0.quantity', 2)
        ->assertJsonPath('customer_name', 'Rubina Akter')
        ->assertJsonPath('resumed_at', fn ($v) => is_string($v));

    // Gone from the list; a second resume is a 409.
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds")->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/admin/pos/holds/{$held->json('id')}/resume")->assertStatus(409);
    expect(PosHeldSale::query()->findOrFail($held->json('id'))->resumed_by)->toBe($this->cashier->id);
});

it('drops a customer name held without a phone (a walk-in)', function (): void {
    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds", [
        'lines' => PosKit::lines([$this->toy->id => 1]),
        'customer_name' => 'করিম উদ্দিন',
    ])->assertCreated()->assertJsonPath('customer_name', null)->assertJsonPath('customer_phone', null);
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

it('discards a held cart once: gone from the list, never resumable, open drawer only', function (): void {
    $held = $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds", ['lines' => PosKit::lines([$this->toy->id => 1])])->assertCreated();
    $kept = $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds", ['lines' => PosKit::lines([$this->toy->id => 2])])->assertCreated();

    $this->deleteJson("/api/v1/admin/pos/holds/{$held->json('id')}")->assertNoContent();
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kept->json('id'));
    $row = PosHeldSale::query()->findOrFail($held->json('id'));
    expect($row->discarded_by)->toBe($this->cashier->id)->and($row->discarded_at)->not->toBeNull();

    // Twice, or resumed after: a 409 `conflict` problem.
    $this->deleteJson("/api/v1/admin/pos/holds/{$held->json('id')}")->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/conflict')
        ->assertJsonPath('detail', 'This held sale has been discarded.');
    $this->postJson("/api/v1/admin/pos/holds/{$held->json('id')}/resume")->assertStatus(409);

    // A resumed hold cannot be discarded.
    $this->postJson("/api/v1/admin/pos/holds/{$kept->json('id')}/resume")->assertOk();
    $this->deleteJson("/api/v1/admin/pos/holds/{$kept->json('id')}")->assertStatus(409);

    // A closed drawer's holds stay as they were.
    $last = $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/holds", ['lines' => PosKit::lines([$this->toy->id => 1])])->assertCreated();
    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/close", ['counted_cash' => '5000'])->assertOk();
    $this->deleteJson("/api/v1/admin/pos/holds/{$last->json('id')}")->assertStatus(409);
    expect(PosHeldSale::query()->findOrFail($last->json('id'))->discarded_at)->toBeNull();
});
