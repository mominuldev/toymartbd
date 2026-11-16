<?php

declare(strict_types=1);

use App\Domain\Finance\Models\CapitalHolder;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Role;
use App\Models\User;
use Tests\Feature\Finance\Capital\CapitalKit;
use Tests\Feature\Finance\FinanceKit;

beforeEach(function (): void {
    $this->user = FinanceKit::actingAs(Role::SuperAdmin);
});

it('adds a holder, optionally linked to a staff account, and audits it', function (): void {
    $staff = User::factory()->create(['name' => 'Rahima Akter']);

    $this->postJson(CapitalKit::URL.'/holders', ['name' => '  Rahima Akter ', 'name_bn' => 'রহিমা আক্তার', 'user_id' => $staff->id])
        ->assertCreated()
        ->assertJsonPath('name', 'Rahima Akter')
        ->assertJsonPath('name_bn', 'রহিমা আক্তার')
        ->assertJsonPath('user.id', $staff->id)
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('entries_count', 0)
        ->assertJsonPath('can.update', true);

    $audit = AuditSensitive::query()->where('action', AuditAction::CapitalHolderSaved->value)->sole();
    expect($audit->after)->toMatchArray(['created' => true, 'name' => 'Rahima Akter', 'user_id' => $staff->id]);
});

it('refuses a duplicate name and a staff account already linked', function (): void {
    $staff = User::factory()->create();
    CapitalHolder::query()->create(['name' => 'Mominul Islam', 'user_id' => $staff->id]);

    $this->postJson(CapitalKit::URL.'/holders', ['name' => 'Mominul Islam'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'name');
    $this->postJson(CapitalKit::URL.'/holders', ['name' => 'Someone', 'user_id' => $staff->id])->assertUnprocessable()->assertJsonPath('errors.0.field', 'user_id');
    $this->postJson(CapitalKit::URL.'/holders', [])->assertUnprocessable()->assertJsonPath('errors.0.field', 'name');
});

it('deactivates and renames a holder, auditing only what changed', function (): void {
    $holder = CapitalKit::holder();
    CapitalKit::record(CapitalKit::body($holder))->assertCreated();

    $this->patchJson(CapitalKit::URL."/holders/{$holder->id}", ['is_active' => false])->assertOk()
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('entries_count', 1);
    $this->patchJson(CapitalKit::URL."/holders/{$holder->id}", ['name' => 'Mominul Islam'])->assertOk(); // unchanged: no audit row

    $audits = AuditSensitive::query()->where('action', AuditAction::CapitalHolderSaved->value)->get();
    expect($audits)->toHaveCount(1)
        ->and($audits->first()->before)->toBe(['is_active' => true])
        ->and($audits->first()->after)->toBe(['is_active' => false]);
});

it('lists holders, active first, with an allowlisted active filter', function (): void {
    CapitalKit::holder('Zahid Hasan');
    CapitalKit::holder('Anwar Hossain', active: false);

    $this->getJson(CapitalKit::URL.'/holders')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Zahid Hasan')
        ->assertJsonPath('data.1.name', 'Anwar Hossain');
    $this->getJson(CapitalKit::URL.'/holders?filter[active]=false')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
});

it('shows capital entries in the activity log under Finance', function (): void {
    $holder = CapitalHolder::query()->findOrFail($this->postJson(CapitalKit::URL.'/holders', ['name' => 'Mominul Islam'])->assertCreated()->json('id'));
    $id = CapitalKit::record(CapitalKit::body($holder, ['kind' => 'drawing']))->json('id');
    CapitalKit::reverse($id);

    $this->getJson('/api/v1/admin/audit?filter[area]=finance')->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.area', 'finance');
    $kinds = collect($this->getJson('/api/v1/admin/audit?filter[area]=finance')->json('data'))->pluck('kind')->sort()->values()->all();
    $subjects = collect($this->getJson('/api/v1/admin/audit?filter[area]=finance')->json('data'))->pluck('subject.type')->unique()->sort()->values()->all();
    expect($kinds)->toBe(['capital_drawing_recorded', 'capital_entry_reversed', 'capital_holder_added'])
        ->and($subjects)->toBe(['capital_entry', 'capital_holder']);
});
