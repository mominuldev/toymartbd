<?php

declare(strict_types=1);

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Models\NotificationTemplateVersion;
use App\Domain\Notification\Services\MessagingSettings;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\RoleMatrix;

/*
 * Message templates library (P7, owner request 2026-11-13): list + filters, versioned
 * edits with restore, custom marketing/manual templates with archive, the WhatsApp
 * approval status, server-measured segments/encoding/cost, and the 403 role matrix.
 */

function libraryAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create(['name' => 'Rafi Ahmed'])->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** @return array<string, mixed> */
function customPayload(array $overrides = []): array
{
    return [
        'name_en' => 'Eid greeting',
        'name_bn' => 'ঈদ শুভেচ্ছা',
        'kind' => 'marketing',
        'channels' => ['sms', 'whatsapp'],
        'variables' => ['name', 'store_name'],
        'body_en' => 'Eid Mubarak {name}! New toys at {store_name}.',
        'body_bn' => 'ঈদ মোবারক {name}! {store_name}-এ নতুন খেলনা।',
        ...$overrides,
    ];
}

/** The rate falls back to config when nobody has saved one in Admin → Integrations. */
function setSmsRate(string $rate): void
{
    config(['notifications.sms.cost_per_segment' => $rate]);
}

it('lists every catalog template, read dynamically, with kind, editor and server SMS cost', function (): void {
    libraryAdmin();

    $rows = collect($this->getJson('/api/v1/admin/notifications/templates')->assertOk()->json('data'));

    // Whatever the catalog holds (other agents add keys) is listed — nothing hardcoded.
    foreach (TemplateCatalog::all() as $key => $definition) {
        foreach (array_keys($definition['channels']) as $channel) {
            expect($rows->where('key', $key)->where('channel', $channel))->toHaveCount(1);
        }
    }

    $sms = $rows->firstWhere(fn (array $r): bool => $r['key'] === 'order.placed' && $r['channel'] === 'sms');
    $perSegment = app(MessagingSettings::class)->costPerSegment();
    expect($sms)->toMatchArray(['kind' => 'system', 'is_custom' => false, 'whatsapp_status' => null, 'archived_at' => null])
        ->and($sms['cost_bn'])->toBe($perSegment->times($sms['segments_bn'])->toDecimal())
        ->and($sms['cost_en'])->toBe($perSegment->times($sms['segments_en'])->toDecimal());

    $wa = $rows->firstWhere(fn (array $r): bool => $r['key'] === 'order.placed' && $r['channel'] === 'whatsapp');
    expect($wa['cost_en'])->toBeNull()
        ->and($wa['whatsapp_status'])->toBe('draft')
        ->and($wa['required_variables'])->toBe(TemplateCatalog::whatsAppParameters('order.placed'));
});

it('prices SMS from the configured per-segment rate, never a hardcoded one', function (): void {
    libraryAdmin();
    setSmsRate('0.50');

    $row = collect($this->getJson('/api/v1/admin/notifications/templates?filter[channel]=sms')->json('data'))->firstWhere('key', 'order.placed');

    expect($row['cost_bn'])->toBe(number_format(0.5 * $row['segments_bn'], 2, '.', ''));
});

it('filters by channel, kind, active, search and archived — and 400s anything unlisted', function (): void {
    libraryAdmin();
    $this->postJson('/api/v1/admin/notifications/templates', customPayload())->assertCreated();
    NotificationTemplate::for('order.cancelled', NotificationChannel::Sms)->update(['is_active' => false]);

    $get = fn (string $q) => collect($this->getJson('/api/v1/admin/notifications/templates?'.$q)->assertOk()->json('data'));

    expect($get('filter[kind]=marketing')->pluck('key')->unique()->all())->toBe(['custom.eid_greeting'])
        ->and($get('filter[kind]=system')->pluck('kind')->unique()->all())->toBe(['system'])
        ->and($get('filter[active]=0')->map(fn ($r) => $r['key'].'/'.$r['channel'])->all())->toBe(['order.cancelled/sms'])
        ->and($get('filter[search]=Eid')->pluck('key')->unique()->all())->toBe(['custom.eid_greeting'])
        ->and($get('filter[search]='.rawurlencode('ঈদ'))->pluck('key')->unique()->all())->toBe(['custom.eid_greeting'])
        ->and($get('filter[channel]=whatsapp&filter[category]=marketing')->pluck('key')->all())->toContain('custom.eid_greeting');

    $this->getJson('/api/v1/admin/notifications/templates?filter[kind]=bogus')->assertStatus(400);
    $this->getJson('/api/v1/admin/notifications/templates?filter[active]=yes')->assertStatus(400);
    $this->getJson('/api/v1/admin/notifications/templates?filter[owner]=1')->assertStatus(400);
});

it('versions every edit, keeps the replaced default as version 1, and restores without rewriting history', function (): void {
    $user = libraryAdmin();
    $template = NotificationTemplate::for('order.placed', NotificationChannel::Sms);
    $original = $template->body_en;
    $put = fn (string $en) => $this->putJson("/api/v1/admin/notifications/templates/{$template->id}", [
        'body_en' => $en, 'body_bn' => $template->body_bn, 'is_active' => true,
    ]);

    $put('Toy Mart BD: {order_number} received. Thanks {name}!')->assertOk()
        ->assertJsonPath('updated_by_name', 'Rafi Ahmed');
    $put('Toy Mart BD: order {order_number} is in.')->assertOk();
    // Saving the same copy again is not a new version.
    $put('Toy Mart BD: order {order_number} is in.')->assertOk();

    $versions = $this->getJson("/api/v1/admin/notifications/templates/{$template->id}/versions")->assertOk()->json('data');
    expect(collect($versions)->pluck('version')->all())->toBe([3, 2, 1])
        ->and($versions[2]['body_en'])->toBe($original)
        ->and($versions[0]['created_by_name'])->toBe('Rafi Ahmed');

    $this->postJson("/api/v1/admin/notifications/templates/{$template->id}/versions/1/restore")->assertOk()
        ->assertJsonPath('body_en', $original)
        ->assertJsonPath('segments_en', 1);

    $latest = NotificationTemplateVersion::query()->where('notification_template_id', $template->id)->orderByDesc('version')->first();
    expect($latest->version)->toBe(4)->and($latest->restored_from)->toBe(1)->and($latest->created_by)->toBe($user->id)
        ->and(NotificationTemplateVersion::query()->count())->toBe(4);

    $this->postJson("/api/v1/admin/notifications/templates/{$template->id}/versions/99/restore")->assertNotFound();
});

it('keeps template versions append-only', function (): void {
    libraryAdmin();
    $this->postJson('/api/v1/admin/notifications/templates', customPayload())->assertCreated();
    $version = NotificationTemplateVersion::query()->firstOrFail();

    expect(fn () => $version->update(['body_en' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $version->delete())->toThrow(LogicException::class);
});

it('creates a custom template per channel under one ASCII key, measured server-side', function (): void {
    libraryAdmin();

    $rows = $this->postJson('/api/v1/admin/notifications/templates', customPayload([
        'channels' => ['sms', 'whatsapp', 'email'],
        'subject_en' => 'Eid Mubarak from {store_name}',
        'subject_bn' => '{store_name} থেকে ঈদ মোবারক',
    ]))->assertCreated(); $rows = $rows->json("data");

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('key')->unique()->all())->toBe(['custom.eid_greeting'])
        ->and($rows[0])->toMatchArray(['kind' => 'marketing', 'category' => 'marketing', 'is_custom' => true, 'name_bn' => 'ঈদ শুভেচ্ছা', 'variables' => ['name', 'store_name']]);

    $sms = collect($rows)->firstWhere('channel', 'sms');
    expect($sms['encoding_en'])->toBe('gsm7')->and($sms['encoding_bn'])->toBe('ucs2')->and($sms['segments_bn'])->toBe(1);
    expect(collect($rows)->firstWhere('channel', 'whatsapp')['whatsapp_status'])->toBe('draft');

    // A second template with the same name gets its own key.
    $this->postJson('/api/v1/admin/notifications/templates', customPayload(['channels' => ['sms']]))->assertCreated()
        ->assertJsonPath('data.0.key', 'custom.eid_greeting_2');

    // Manual templates follow the order-updates opt-out category.
    $this->postJson('/api/v1/admin/notifications/templates', customPayload(['kind' => 'manual', 'name_en' => 'Call back', 'channels' => ['sms']]))
        ->assertCreated()->assertJsonPath('data.0.category', 'order_updates');
});

it('refuses a custom template with a variable outside its chosen generic set', function (): void {
    libraryAdmin();

    $this->postJson('/api/v1/admin/notifications/templates', customPayload(['body_en' => 'Hi {name}, pay {total} now']))
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'body_en');
    $this->postJson('/api/v1/admin/notifications/templates', customPayload(['variables' => ['name', 'cod_amount']]))
        ->assertStatus(422);
    $this->postJson('/api/v1/admin/notifications/templates', customPayload(['kind' => 'order_updates']))->assertStatus(422);
    $this->postJson('/api/v1/admin/notifications/templates', customPayload(['channels' => ['email']]))
        ->assertStatus(422); // email needs subjects

    expect(NotificationTemplate::query()->where('kind', '!=', 'system')->count())->toBe(0);
});

it('archives and restores a custom template, and never archives or deletes a system one', function (): void {
    libraryAdmin();
    $id = $this->postJson('/api/v1/admin/notifications/templates', customPayload())->json('data.0.id');

    $this->postJson("/api/v1/admin/notifications/templates/{$id}/archive")->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.is_active', false);
    expect(collect($this->getJson('/api/v1/admin/notifications/templates')->json('data'))->pluck('key'))->not->toContain('custom.eid_greeting')
        ->and(collect($this->getJson('/api/v1/admin/notifications/templates?filter[archived]=1')->json('data'))->pluck('key')->unique()->all())->toBe(['custom.eid_greeting']);

    // Archived copy is frozen.
    $r = $this->putJson("/api/v1/admin/notifications/templates/{$id}", ["body_en" => "x {name}", "body_bn" => "y", "is_active" => true]); $r->assertStatus(409);

    $this->postJson("/api/v1/admin/notifications/templates/{$id}/unarchive")->assertOk()->assertJsonPath('data.0.archived_at', null);

    $system = NotificationTemplate::for('order.placed', NotificationChannel::Sms);
    $this->postJson("/api/v1/admin/notifications/templates/{$system->id}/archive")->assertStatus(409)
        ->assertJsonPath('type', fn ($type) => is_string($type) && str_contains($type, 'conflict'));
    $this->deleteJson("/api/v1/admin/notifications/templates/{$system->id}")->assertStatus(405);
    $this->deleteJson("/api/v1/admin/notifications/templates/{$id}")->assertStatus(405);
    expect(NotificationTemplate::query()->find($system->id)?->archived_at)->toBeNull();
});

it('never re-keys a system template: key, channel and kind in the body are ignored', function (): void {
    libraryAdmin();
    $template = NotificationTemplate::for('order.shipped', NotificationChannel::Sms);

    $this->putJson("/api/v1/admin/notifications/templates/{$template->id}", [
        'key' => 'order.hijacked', 'channel' => 'email', 'kind' => 'marketing', 'name_en' => 'Renamed',
        'body_en' => $template->body_en, 'body_bn' => $template->body_bn, 'is_active' => true,
    ])->assertOk()->assertJsonPath('key', 'order.shipped')->assertJsonPath('kind', 'system')->assertJsonPath('name_en', null);
});

it('refuses unknown variables and WhatsApp bodies that drop a Meta-approved parameter', function (): void {
    libraryAdmin();
    $wa = NotificationTemplate::for('order.placed', NotificationChannel::WhatsApp);
    $params = TemplateCatalog::whatsAppParameters('order.placed');
    expect($params)->not->toBe([]);
    $dropped = str_replace('{'.$params[0].'}', '', $wa->body_en);

    $this->putJson("/api/v1/admin/notifications/templates/{$wa->id}", [
        'body_en' => $dropped, 'body_bn' => $wa->body_bn, 'is_active' => true,
    ])->assertStatus(422)->assertJsonPath('errors.0.field', 'body_en');

    $preview = $this->postJson("/api/v1/admin/notifications/templates/{$wa->id}/preview", [
        'body_en' => $dropped.' {coupon}', 'body_bn' => $wa->body_bn,
    ])->assertOk();
    expect($preview->json('missing_variables'))->toBe([$params[0]])
        ->and($preview->json('unknown_variables'))->toBe(['coupon']);
});

it('measures GSM-7 160/153 and Bangla UCS-2 70/67 server-side and warns at 3+ Bangla segments', function (): void {
    libraryAdmin();
    setSmsRate('0.26');
    $sms = NotificationTemplate::for('order.placed', NotificationChannel::Sms);
    $preview = fn (string $en, string $bn) => $this->postJson("/api/v1/admin/notifications/templates/{$sms->id}/preview", ['body_en' => $en, 'body_bn' => $bn])->assertOk();

    $r = $preview(str_repeat('a', 160), str_repeat('অ', 70));
    expect($r->json('en'))->toMatchArray(['encoding' => 'gsm7', 'segments' => 1, 'per_segment_limit' => 160, 'cost' => '0.26'])
        ->and($r->json('bn'))->toMatchArray(['encoding' => 'ucs2', 'segments' => 1, 'per_segment_limit' => 70, 'cost' => '0.26'])
        ->and($r->json('warnings'))->toBe([]);

    $r = $preview(str_repeat('a', 161), str_repeat('অ', 71));
    expect($r->json('en'))->toMatchArray(['segments' => 2, 'per_segment_limit' => 153, 'cost' => '0.52'])
        ->and($r->json('bn'))->toMatchArray(['segments' => 2, 'per_segment_limit' => 67, 'cost' => '0.52']);

    $r = $preview('short', str_repeat('অ', 135)); // 135 > 2 × 67
    expect($r->json('bn'))->toMatchArray(['segments' => 3, 'cost' => '0.78'])
        ->and($r->json('warnings'))->toBe(['long_sms_bn']);
});

it('records the WhatsApp approval status, and an edit to approved copy drops it back to draft', function (): void {
    libraryAdmin();
    $wa = NotificationTemplate::for('order.shipped', NotificationChannel::WhatsApp);
    $sms = NotificationTemplate::for('order.shipped', NotificationChannel::Sms);

    $this->putJson("/api/v1/admin/notifications/templates/{$wa->id}/whatsapp-status", ['status' => 'approved'])->assertOk()
        ->assertJsonPath('whatsapp_status', 'approved');
    $this->putJson("/api/v1/admin/notifications/templates/{$wa->id}/whatsapp-status", ['status' => 'live'])->assertStatus(422);
    $this->putJson("/api/v1/admin/notifications/templates/{$sms->id}/whatsapp-status", ['status' => 'approved'])->assertStatus(409);

    // Toggling only is_active keeps approval; changing the body resets it.
    $this->putJson("/api/v1/admin/notifications/templates/{$wa->id}", ['body_en' => $wa->body_en, 'body_bn' => $wa->body_bn, 'is_active' => false])
        ->assertOk()->assertJsonPath('whatsapp_status', 'approved');
    $this->putJson("/api/v1/admin/notifications/templates/{$wa->id}", ['body_en' => $wa->body_en.' Thanks!', 'body_bn' => $wa->body_bn, 'is_active' => true])
        ->assertOk()->assertJsonPath('whatsapp_status', 'draft');
});

it('guards POST /notifications/templates with settings.update', function (): void {
    $n = 0;
    RoleMatrix::assert($this, Permission::SettingsUpdate, function () use (&$n) {
        return $this->postJson('/api/v1/admin/notifications/templates', customPayload(['name_en' => 'Matrix '.$n++, 'channels' => ['sms']]));
    }, 201);
});

it('guards POST /notifications/templates/{template}/archive and /unarchive with settings.update', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $id = app(App\Domain\Notification\Actions\CreateCustomTemplate::class)->handle(new App\Domain\Notification\DTOs\CustomTemplateData(
        'Matrix', 'ম্যাট্রিক্স', App\Domain\Notification\Enums\TemplateKind::Manual, [NotificationChannel::Sms], ['name'], 'Hi {name}', 'হাই {name}',
    ), null)[0]->id;

    RoleMatrix::assert($this, Permission::SettingsUpdate, fn () => $this->postJson("/api/v1/admin/notifications/templates/{$id}/archive"));
    RoleMatrix::assert($this, Permission::SettingsUpdate, fn () => $this->postJson("/api/v1/admin/notifications/templates/{$id}/unarchive"));
});

it('guards GET /notifications/templates/{template}/versions with settings.view', function (): void {
    $id = NotificationTemplate::for('order.placed', NotificationChannel::Sms)->id;
    RoleMatrix::assert($this, Permission::SettingsView, fn () => $this->getJson("/api/v1/admin/notifications/templates/{$id}/versions"));
});

it('guards POST /notifications/templates/{template}/versions/{version}/restore with settings.update', function (): void {
    $template = NotificationTemplate::for('order.placed', NotificationChannel::Sms);
    App\Domain\Notification\Support\TemplateVersions::append($template, null);
    RoleMatrix::assert($this, Permission::SettingsUpdate, fn () => $this->postJson("/api/v1/admin/notifications/templates/{$template->id}/versions/1/restore"));
});

it('guards PUT /notifications/templates/{template}/whatsapp-status with settings.update', function (): void {
    $id = NotificationTemplate::for('order.placed', NotificationChannel::WhatsApp)->id;
    RoleMatrix::assert($this, Permission::SettingsUpdate, fn () => $this->putJson("/api/v1/admin/notifications/templates/{$id}/whatsapp-status", ['status' => 'submitted']));
});
