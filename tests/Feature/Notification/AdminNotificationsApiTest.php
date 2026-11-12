<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RoleMatrix;

/*
 * Admin notifications API (P4-NOT-01/03, P4-I18N-03): template editor with server-side
 * segment counts, the message log with cost and dead letters, customer opt-outs, and the
 * 403 role matrix (rule 21).
 */

function actingAsNotificationAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

function smsTemplate(string $key = 'order.placed'): NotificationTemplate
{
    return NotificationTemplate::for($key, NotificationChannel::Sms);
}

function logRow(array $overrides = []): NotificationLog
{
    return NotificationLog::query()->create([
        'channel' => NotificationChannel::Sms,
        'template' => 'order.placed',
        'category' => NotificationCategory::OrderUpdates,
        'locale' => 'bn',
        'recipient' => '017****5678',
        'notifiable_type' => NotificationLog::NOTIFIABLE_ORDER,
        'notifiable_id' => 1,
        'payload' => ['order_number' => 'TMB-26-00482'],
        'status' => NotificationStatus::Sent,
        'segments' => 2,
        'cost' => '1.28',
        'sent_at' => now(),
        ...$overrides,
    ]);
}

it('lists templates with variables, counts and an allowlisted filter only', function (): void {
    actingAsNotificationAdmin();

    $rows = $this->getJson('/api/v1/admin/notifications/templates?filter[channel]=sms')->assertOk()->json('data');

    $placed = collect($rows)->firstWhere('key', 'order.placed');
    expect(collect($rows)->pluck('channel')->unique()->all())->toBe(['sms'])
        ->and($placed)->toMatchArray(['segments_en' => 1, 'segments_bn' => 2, 'encoding_bn' => 'ucs2', 'can_opt_out' => true])
        ->and($placed['variables'])->toContain('order_number');

    $this->getJson('/api/v1/admin/notifications/templates?filter[bogus]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/notifications/templates?filter[channel]=fax')->assertStatus(400);
    $this->getJson('/api/v1/admin/notifications/templates?sort=key')->assertStatus(400);
});

it('updates a template and re-measures both locales server-side', function (): void {
    $user = actingAsNotificationAdmin();
    $template = smsTemplate();
    $longBn = str_repeat('অ', 140).' {order_number}';

    $response = $this->putJson("/api/v1/admin/notifications/templates/{$template->id}", [
        'body_en' => 'Toy Mart BD: {order_number} received.',
        'body_bn' => $longBn,
        'is_active' => true,
    ])->assertOk();

    expect($response->json())->toMatchArray(['segments_en' => 1, 'segments_bn' => 3, 'chars_bn' => 153])
        ->and($template->refresh()->updated_by)->toBe($user->id);
});

it('rejects a variable outside the template allowlist', function (): void {
    actingAsNotificationAdmin();
    $template = smsTemplate();

    $errors = $this->putJson("/api/v1/admin/notifications/templates/{$template->id}", [
        'body_en' => 'Hi {customer_phone}',
        'body_bn' => 'ঠিকানা {address}',
        'is_active' => true,
    ])->assertUnprocessable()->json('errors');

    expect(collect($errors)->pluck('field')->all())->toContain('body_en', 'body_bn');
});

it('requires a subject for an email template', function (): void {
    actingAsNotificationAdmin();
    $email = NotificationTemplate::for('order.placed', NotificationChannel::Email);

    $fields = collect($this->putJson("/api/v1/admin/notifications/templates/{$email->id}", [
        'body_en' => 'Order {order_number}', 'body_bn' => 'অর্ডার {order_number}', 'is_active' => true,
    ])->assertUnprocessable()->json('errors'))->pluck('field')->all();

    expect($fields)->toContain('subject_en', 'subject_bn');
});

it('previews an unsaved body with segments and per-send cost, writing nothing', function (): void {
    actingAsNotificationAdmin();
    config(['notifications.sms.cost_per_segment' => '0.64']);
    $template = smsTemplate();
    $before = $template->body_bn;

    $preview = $this->postJson("/api/v1/admin/notifications/templates/{$template->id}/preview", [
        'body_en' => 'Order {order_number} {nope}',
        'body_bn' => str_repeat('ক', 71),
    ])->assertOk()->json();

    expect($preview['en'])->toMatchArray(['rendered' => 'Order TMB-26-00482 {nope}', 'segments' => 1, 'encoding' => 'gsm7', 'per_segment_limit' => 160, 'cost' => '0.64'])
        ->and($preview['bn'])->toMatchArray(['chars' => 71, 'segments' => 2, 'encoding' => 'ucs2', 'per_segment_limit' => 67, 'cost' => '1.28'])
        ->and($preview['unknown_variables'])->toBe(['nope'])
        ->and($template->refresh()->body_bn)->toBe($before);
});

it('lists the log newest first with masked recipients and allowlisted filters', function (): void {
    actingAsNotificationAdmin();
    logRow();
    $dead = logRow(['status' => NotificationStatus::DeadLettered, 'error' => 'timeout', 'sent_at' => null]);

    $rows = $this->getJson('/api/v1/admin/notifications/logs')->assertOk()->json('data');
    expect($rows[0]['id'])->toBe($dead->id)
        ->and($rows[1])->toMatchArray(['recipient' => '017****5678', 'cost' => '1.28', 'order_number' => 'TMB-26-00482'])
        ->and($rows[1])->not->toHaveKey('payload');

    expect($this->getJson('/api/v1/admin/notifications/logs?filter[status]=dead_lettered')->json('data'))->toHaveCount(1);
    $this->getJson('/api/v1/admin/notifications/logs?filter[status]=lost')->assertStatus(400);
    $this->getJson('/api/v1/admin/notifications/logs?filter[phone]=017')->assertStatus(400);
    $this->getJson('/api/v1/admin/notifications/logs?sort=cost')->assertStatus(400);
});

it('summarises this month’s spend per channel and the open dead letters', function (): void {
    actingAsNotificationAdmin();
    logRow();
    logRow(['channel' => NotificationChannel::WhatsApp, 'cost' => '0.45', 'segments' => null]);
    logRow(['status' => NotificationStatus::DeadLettered, 'sent_at' => null]);

    $summary = $this->getJson('/api/v1/admin/notifications/logs/summary')->assertOk()->json();

    expect($summary['dead_lettered'])->toBe(1)
        ->and($summary['total_cost'])->toBe('1.73')
        ->and(collect($summary['channels'])->firstWhere('channel', 'sms'))->toMatchArray(['sent' => 1, 'segments' => 2, 'cost' => '1.28']);
});

it('retries a dead letter and refuses anything else with 409', function (): void {
    actingAsNotificationAdmin();
    Queue::fake();
    $dead = logRow(['status' => NotificationStatus::DeadLettered, 'error' => 'timeout', 'sent_at' => null]);
    $sent = logRow();

    $this->postJson("/api/v1/admin/notifications/logs/{$dead->id}/retry")->assertOk()->assertJsonPath('status', 'queued');
    Queue::assertPushedOn('notify', App\Domain\Notification\Jobs\SendNotification::class);

    $this->postJson("/api/v1/admin/notifications/logs/{$sent->id}/retry")->assertStatus(409);
});

it('shows and updates a customer’s preferences, keeping security always on', function (): void {
    actingAsNotificationAdmin();
    $customer = Customer::factory()->create();

    $matrix = collect($this->getJson("/api/v1/admin/notifications/customers/{$customer->id}/preferences")->assertOk()->json('data'));
    expect($matrix)->toHaveCount(9)
        ->and($matrix->firstWhere(fn ($r) => $r['channel'] === 'sms' && $r['category'] === 'marketing')['enabled'])->toBeFalse()
        ->and($matrix->firstWhere(fn ($r) => $r['channel'] === 'sms' && $r['category'] === 'security'))->toMatchArray(['enabled' => true, 'can_opt_out' => false]);

    $updated = collect($this->putJson("/api/v1/admin/notifications/customers/{$customer->id}/preferences", [
        'preferences' => [['channel' => 'sms', 'category' => 'order_updates', 'enabled' => false]],
    ])->assertOk()->json('data'));
    expect($updated->firstWhere(fn ($r) => $r['channel'] === 'sms' && $r['category'] === 'order_updates'))->toMatchArray(['enabled' => false, 'is_default' => false]);

    $this->putJson("/api/v1/admin/notifications/customers/{$customer->id}/preferences", [
        'preferences' => [['channel' => 'sms', 'category' => 'security', 'enabled' => false]],
    ])->assertUnprocessable();
});

// --- 403 role matrix (rule 21) -------------------------------------------------------

it('guards GET /notifications/templates with settings.view', function (): void {
    RoleMatrix::assert($this, Permission::SettingsView, fn () => $this->getJson('/api/v1/admin/notifications/templates'));
});

it('guards GET /notifications/templates/{template} with settings.view', function (): void {
    $id = smsTemplate()->id;
    RoleMatrix::assert($this, Permission::SettingsView, fn () => $this->getJson("/api/v1/admin/notifications/templates/{$id}"));
});

it('guards PUT /notifications/templates/{template} with settings.update', function (): void {
    $id = smsTemplate()->id;
    RoleMatrix::assert($this, Permission::SettingsUpdate, fn () => $this->putJson("/api/v1/admin/notifications/templates/{$id}", [
        'body_en' => 'Order {order_number}', 'body_bn' => 'অর্ডার {order_number}', 'is_active' => true,
    ]));
});

it('guards POST /notifications/templates/{template}/preview with settings.view', function (): void {
    $id = smsTemplate()->id;
    RoleMatrix::assert($this, Permission::SettingsView, fn () => $this->postJson("/api/v1/admin/notifications/templates/{$id}/preview", [
        'body_en' => 'x', 'body_bn' => 'ক',
    ]));
});

it('guards GET /notifications/logs with settings.view', function (): void {
    RoleMatrix::assert($this, Permission::SettingsView, fn () => $this->getJson('/api/v1/admin/notifications/logs'));
});

it('guards GET /notifications/logs/summary with settings.view', function (): void {
    RoleMatrix::assert($this, Permission::SettingsView, fn () => $this->getJson('/api/v1/admin/notifications/logs/summary'));
});

it('guards POST /notifications/logs/{log}/retry with settings.update', function (): void {
    Queue::fake();
    RoleMatrix::assert($this, Permission::SettingsUpdate, function () {
        $dead = logRow(['status' => NotificationStatus::DeadLettered, 'sent_at' => null]);

        return $this->postJson("/api/v1/admin/notifications/logs/{$dead->id}/retry");
    });
});

it('guards GET /notifications/customers/{customer}/preferences with customers.view', function (): void {
    $customer = Customer::factory()->create();
    RoleMatrix::assert($this, Permission::CustomersView, fn () => $this->getJson("/api/v1/admin/notifications/customers/{$customer->id}/preferences"));
});

it('guards PUT /notifications/customers/{customer}/preferences with customers.update', function (): void {
    $customer = Customer::factory()->create();
    RoleMatrix::assert($this, Permission::CustomersUpdate, fn () => $this->putJson("/api/v1/admin/notifications/customers/{$customer->id}/preferences", [
        'preferences' => [['channel' => 'whatsapp', 'category' => 'marketing', 'enabled' => true]],
    ]));
});

it('refuses the storefront bearer token on the admin notifications API', function (): void {
    $token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/notifications/logs')->assertStatus(403);
});
