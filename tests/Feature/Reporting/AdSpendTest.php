<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketing\Models\AdSpend;
use App\Domain\Ordering\Models\Order;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\RoleMatrix;

/*
 * Ad spend typed in for Marketing ROI (P10): listing with reports.view, writing with
 * marketing.manage, one entry per campaign and month, every write audited.
 */

const SPEND_URL = '/api/v1/admin/analytics/marketing/spend';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 06:00:00', 'UTC'));
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->withRole(Role::Manager)->create(['name' => 'Mominul'])->refresh();
    $this->actingAs($this->user);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('adds ad spend, trims the campaign, and audits it', function (): void {
    $this->postJson(SPEND_URL, ['campaign' => '  Winter Toy Fest ', 'source' => 'Facebook', 'month' => '2026-11', 'amount' => '4000', 'note' => 'শীতের খেলনা উৎসব'])
        ->assertCreated()
        ->assertJsonPath('campaign', 'Winter Toy Fest')
        ->assertJsonPath('source', 'facebook')
        ->assertJsonPath('month', '2026-11')
        ->assertJsonPath('amount', '4000.00')
        ->assertJsonPath('note', 'শীতের খেলনা উৎসব')
        ->assertJsonPath('entered_by.name', 'Mominul')
        ->assertJsonPath('entered_on', '2026-11-17')
        ->assertJsonPath('can', ['update' => true, 'delete' => true]);

    $audit = AuditSensitive::query()->where('action', AuditAction::AdSpendCreated->value)->sole();
    expect($audit->user_id)->toBe($this->user->id)
        ->and($audit->after)->toBe(['campaign' => 'Winter Toy Fest', 'source' => 'facebook', 'month' => '2026-11', 'amount' => '4000.00', 'note' => 'শীতের খেলনা উৎসব']);
});

it('changes ad spend and audits only what changed', function (): void {
    $spend = AdSpend::factory()->create(['campaign' => 'eid-retarget', 'month' => '2026-11-01', 'amount' => '3500.00', 'created_by' => $this->user->id]);

    $this->putJson(SPEND_URL.'/'.$spend->id, ['campaign' => 'eid-retarget', 'source' => 'facebook', 'month' => '2026-11', 'amount' => '3750.50', 'note' => $spend->note])
        ->assertOk()
        ->assertJsonPath('amount', '3750.50')
        ->assertJsonPath('changed_by.name', 'Mominul');

    $audit = AuditSensitive::query()->where('action', AuditAction::AdSpendUpdated->value)->sole();
    expect($audit->model_id)->toBe($spend->id)
        ->and($audit->before)->toBe(['campaign' => 'eid-retarget', 'amount' => '3500.00'])
        ->and($audit->after)->toBe(['campaign' => 'eid-retarget', 'amount' => '3750.50']);

    // Nothing changed: no second audit row.
    $this->putJson(SPEND_URL.'/'.$spend->id, ['campaign' => 'eid-retarget', 'source' => 'facebook', 'month' => '2026-11', 'amount' => '3750.50', 'note' => $spend->note])->assertOk();
    expect(AuditSensitive::query()->where('action', AuditAction::AdSpendUpdated->value)->count())->toBe(1);
});

it('deletes ad spend and keeps what it was in the audit', function (): void {
    $spend = AdSpend::factory()->create(['campaign' => 'search-ride-on', 'source' => 'google', 'month' => '2026-10-01', 'amount' => '2000.00']);

    $this->deleteJson(SPEND_URL.'/'.$spend->id)->assertNoContent();

    expect(AdSpend::query()->find($spend->id))->toBeNull();
    $audit = AuditSensitive::query()->where('action', AuditAction::AdSpendDeleted->value)->sole();
    expect($audit->before)->toMatchArray(['campaign' => 'search-ride-on', 'month' => '2026-10', 'amount' => '2000.00'])
        ->and($audit->after)->toBeNull()
        ->and($audit->action->area())->toBe('finance');
});

it('lists the range’s entries, newest month first, with campaign suggestions from orders', function (): void {
    AdSpend::factory()->create(['campaign' => 'winter-toy-fest', 'month' => '2026-10-01', 'amount' => '4000.00']);
    AdSpend::factory()->create(['campaign' => 'eid-retarget', 'month' => '2026-11-01', 'amount' => '3500.00']);
    AdSpend::factory()->create(['campaign' => 'old', 'month' => '2026-06-01', 'amount' => '100.00']);
    $customer = Customer::factory()->create();
    Order::factory()->create(['customer_id' => $customer->id, 'utm_campaign' => 'winter-toy-fest', 'placed_at' => CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC')]);
    Order::factory()->create(['customer_id' => $customer->id, 'utm_campaign' => 'Winter-Toy-Fest', 'placed_at' => CarbonImmutable::parse('2026-11-01 06:00:00', 'UTC')]);
    Order::factory()->create(['customer_id' => $customer->id, 'utm_campaign' => 'eid-retarget', 'placed_at' => CarbonImmutable::parse('2026-11-10 06:00:00', 'UTC')]);

    $this->getJson(SPEND_URL.'?range=custom&filter[from]=2026-10-15&filter[to]=2026-11-17')
        ->assertOk()
        ->assertJsonPath('data.*.campaign', ['eid-retarget', 'winter-toy-fest'])
        ->assertJsonPath('meta.campaigns', ['eid-retarget', 'winter-toy-fest']);

    $this->getJson(SPEND_URL.'?filter[campaign]=x')->assertStatus(400);
});

it('refuses a negative or zero amount, a bad month and a duplicate campaign + month', function (): void {
    AdSpend::factory()->create(['campaign' => 'winter-toy-fest', 'month' => '2026-11-01']);
    $valid = ['campaign' => 'eid-retarget', 'month' => '2026-11', 'amount' => '100.00'];
    $field = fn (array $body) => $this->postJson(SPEND_URL, [...$valid, ...$body])->assertUnprocessable()->json('errors.0.field');

    expect($field(['amount' => '-50.00']))->toBe('amount')
        ->and($field(['amount' => '0']))->toBe('amount')
        ->and($field(['amount' => '12.345']))->toBe('amount')
        ->and($field(['month' => '2026-13']))->toBe('month')
        ->and($field(['month' => '2026-11-01']))->toBe('month')
        ->and($field(['month' => '2026-12']))->toBe('month')       // a future month
        ->and($field(['month' => '2019-12']))->toBe('month')
        ->and($field(['campaign' => '   ']))->toBe('campaign')
        ->and($field(['campaign' => 'WINTER-TOY-FEST']))->toBe('month'); // already entered for Nov

    // Editing an entry may keep its own campaign and month.
    $own = AdSpend::query()->where('campaign', 'winter-toy-fest')->sole();
    $this->putJson(SPEND_URL.'/'.$own->id, ['campaign' => 'winter-toy-fest', 'month' => '2026-11', 'amount' => '10.00'])->assertOk();
    expect(AdSpend::query()->count())->toBe(1)
        ->and(AuditSensitive::query()->where('action', AuditAction::AdSpendCreated->value)->count())->toBe(0);
});

it('guards GET /analytics/marketing/spend with reports.view', function (): void {
    RoleMatrix::assert($this, Permission::ReportsView, fn () => $this->getJson(SPEND_URL));
});

it('guards POST /analytics/marketing/spend with marketing.manage', function (): void {
    $n = 0;
    RoleMatrix::assert($this, Permission::MarketingManage, function () use (&$n) {
        $n++;

        return $this->postJson(SPEND_URL, ['campaign' => "campaign-{$n}", 'month' => '2026-11', 'amount' => '100.00']);
    }, 201);
});

it('guards PUT /analytics/marketing/spend/{adSpend} with marketing.manage', function (): void {
    RoleMatrix::assert($this, Permission::MarketingManage, function () {
        $spend = AdSpend::factory()->create();

        return $this->putJson(SPEND_URL.'/'.$spend->id, ['campaign' => $spend->campaign, 'month' => '2026-11', 'amount' => '250.00']);
    });
});

it('guards DELETE /analytics/marketing/spend/{adSpend} with marketing.manage', function (): void {
    RoleMatrix::assert($this, Permission::MarketingManage, fn () => $this->deleteJson(SPEND_URL.'/'.AdSpend::factory()->create()->id), 204);
});
