<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\RoleMatrix;

/*
 * Activity log (Figma B · 39): GET /audit and GET /audit/export over the insert-only
 * audit_sensitive trail. Filters are allowlisted, dates are Dhaka dates, the IP is masked
 * and the User-Agent reduced to "Chrome · macOS" server-side, subjects are labelled in batch.
 */

const AUDIT_URL = '/api/v1/admin/audit';
const MAC_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function auditOwner(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $owner = User::factory()->withRole(Role::SuperAdmin)->create(['name' => 'Mominul Islam'])->refresh();
    test()->actingAs($owner, 'web');

    return $owner;
}

/** @param  array<string, mixed>  $attributes */
function audit(array $attributes): AuditSensitive
{
    return AuditSensitive::query()->create($attributes);
}

function costChange(User $actor, ?ProductVariant $variant = null): AuditSensitive
{
    $variant ??= ProductVariant::factory()->for(Product::factory()->withoutVariants()->state(['name' => 'Brick Box 500']))->create(['name' => 'Classic']);

    return audit([
        'user_id' => $actor->id,
        'action' => AuditAction::CostChanged,
        'model_type' => $variant->getMorphClass(),
        'model_id' => $variant->id,
        'before' => ['cost_price' => '1050.00'],
        'after' => ['cost_price' => '1120.00'],
        'ip' => '103.108.140.21',
        'user_agent' => MAC_CHROME,
    ]);
}

it('lists newest first with actor, subject, changes, area, Dhaka day, masked IP and device', function (): void {
    $owner = auditOwner();
    $this->travelTo('2026-11-08 04:04:00'); // 10:04 Dhaka
    costChange($owner);
    $this->travelTo('2026-11-08 05:30:00');
    audit(['action' => AuditAction::CredentialChanged, 'before' => ['scope' => 'messaging', 'sms_sender_id' => 'OLD'], 'after' => ['scope' => 'messaging', 'sms_sender_id' => 'TOYMART']]);

    $response = $this->getJson(AUDIT_URL)->assertOk()->assertJsonCount(2, 'data');

    $system = $response->json('data.0');
    expect($system['actor'])->toBeNull()
        ->and($system['kind'])->toBe('messaging_settings')
        ->and($system['area'])->toBe('integrations')
        ->and($system['subject'])->toBeNull()
        ->and($system['changes'])->toBe([['field' => 'sms_sender_id', 'before' => 'OLD', 'after' => 'TOYMART']])
        ->and($system['ip'])->toBeNull();

    $cost = $response->json('data.1');
    expect($cost['action'])->toBe('cost_changed')
        ->and($cost['kind'])->toBe('cost_changed')
        ->and($cost['area'])->toBe('catalog')
        ->and($cost['actor'])->toBe(['id' => $owner->id, 'name' => 'Mominul Islam'])
        ->and($cost['subject']['type'])->toBe('variant')
        ->and($cost['subject']['label'])->toBe('Brick Box 500 / Classic')
        ->and($cost['changes'])->toBe([['field' => 'cost_price', 'before' => '1050.00', 'after' => '1120.00']])
        ->and($cost['day'])->toBe('2026-11-08')
        ->and($cost['ip'])->toBe('103.108.xx.xx')
        ->and($cost['device'])->toBe('Chrome · macOS')
        ->and($response->json('meta'))->toHaveKey('next_cursor');
});

it('never returns the full IP, the raw User-Agent or a two-factor secret', function (): void {
    $owner = auditOwner();
    costChange($owner);
    audit(['user_id' => $owner->id, 'action' => AuditAction::CredentialChanged, 'model_type' => $owner->getMorphClass(), 'model_id' => $owner->id,
        'before' => ['scope' => 'two_factor'], 'after' => ['scope' => 'two_factor', 'two_factor' => 'enabled'], 'ip' => '2001:db8:85a3::8a2e:370:7334', 'user_agent' => MAC_CHROME]);

    $body = $this->getJson(AUDIT_URL)->assertOk()->getContent();

    expect($body)->not->toContain('103.108.140.21')
        ->not->toContain('140.21')
        ->not->toContain('8a2e')
        ->not->toContain('AppleWebKit')
        ->not->toContain('user_agent')
        ->not->toContain('two_factor_secret')
        ->toContain('2001:db8:xxxx::');

    $row = $this->getJson(AUDIT_URL)->json('data.0');
    expect($row['kind'])->toBe('two_factor_enabled')->and($row['area'])->toBe('staff')
        ->and($row['subject'])->toBe(['type' => 'user', 'id' => $owner->id, 'label' => 'Mominul Islam']);
});

it('records the IP and device of a real request', function (): void {
    auditOwner();

    $this->withServerVariables(['REMOTE_ADDR' => '103.108.140.21'])
        ->withHeader('User-Agent', MAC_CHROME.str_repeat('x', 400))
        ->putJson('/api/v1/admin/staff/roles/viewer', ['permissions' => [...Role::Viewer->permissionNames(), 'finance.view']])
        ->assertOk();

    $stored = AuditSensitive::query()->sole();
    expect($stored->ip)->toBe('103.108.140.21')
        ->and(mb_strlen((string) $stored->user_agent))->toBe(AuditSensitive::USER_AGENT_MAX);

    $row = $this->getJson(AUDIT_URL)->json('data.0');
    expect($row['kind'])->toBe('permissions_changed')
        ->and($row['subject'])->toMatchArray(['type' => 'role', 'label' => 'viewer'])
        ->and($row['changes'])->toBe([['field' => 'permissions', 'before' => null, 'after' => '+finance.view']])
        ->and($row['ip'])->toBe('103.108.xx.xx')
        ->and($row['device'])->toBe('Chrome · macOS');
});

it('records no IP or device outside a routed request (a command, a queued job)', function (): void {
    $console = audit(['action' => AuditAction::ScheduledReportResent, 'after' => ['status' => 'queued']]);

    expect($console->ip)->toBeNull()->and($console->user_agent)->toBeNull();
});

it('filters by person, area and action', function (): void {
    $owner = auditOwner();
    $rina = User::factory()->withRole(Role::Accountant)->create(['name' => 'Rina Akter']);
    costChange($owner);
    costChange($rina);
    audit(['user_id' => $owner->id, 'action' => AuditAction::CredentialChanged, 'model_type' => $rina->getMorphClass(), 'model_id' => $rina->id, 'before' => ['scope' => 'staff_password'], 'after' => ['scope' => 'staff_password', 'password' => 'changed']]);
    audit(['user_id' => $owner->id, 'action' => AuditAction::CredentialChanged, 'before' => ['scope' => 'tracking'], 'after' => ['scope' => 'tracking', 'ga4_id' => 'G-1']]);

    expect($this->getJson(AUDIT_URL.'?filter[user_id]='.$rina->id)->assertOk()->json('data.*.actor.name'))->toBe(['Rina Akter'])
        ->and($this->getJson(AUDIT_URL.'?filter[area]=catalog')->json('data.*.kind'))->toBe(['cost_changed', 'cost_changed'])
        ->and($this->getJson(AUDIT_URL.'?filter[area]=staff')->json('data.*.kind'))->toBe(['password_reset'])
        ->and($this->getJson(AUDIT_URL.'?filter[area]=integrations')->json('data.*.kind'))->toBe(['tracking_settings'])
        ->and($this->getJson(AUDIT_URL.'?filter[action]=credential_changed')->json('data'))->toHaveCount(2)
        ->and($this->getJson(AUDIT_URL.'?filter[area]=refunds')->json('data'))->toBe([]);
});

it('refuses unlisted filters, sorting and malformed values with a 400', function (string $query): void {
    auditOwner();

    $this->getJson(AUDIT_URL.'?'.$query)
        ->assertStatus(400)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    'unlisted filter' => 'filter[ip]=1.2.3.4',
    'sort' => 'sort=-created_at',
    'unknown area' => 'filter[area]=payroll',
    'unknown action' => 'filter[action]=deleted',
    'bad user id' => 'filter[user_id]=abc',
    'bad date' => 'filter[from]=2026-13-01',
    'reversed range' => 'filter[from]=2026-11-08&filter[to]=2026-11-01',
]);

it('buckets dates by the Dhaka calendar day, not UTC', function (): void {
    $owner = auditOwner();
    // 19:30 UTC on the 7th is 01:30 on the 8th in Dhaka.
    $this->travelTo('2026-11-07 19:30:00');
    costChange($owner);
    $this->travelBack();

    expect($this->getJson(AUDIT_URL.'?filter[from]=2026-11-08&filter[to]=2026-11-08')->json('data.0.day'))->toBe('2026-11-08')
        ->and($this->getJson(AUDIT_URL.'?filter[from]=2026-11-07&filter[to]=2026-11-07')->json('data'))->toBe([]);
});

it('labels subjects in batch, without a query per row', function (): void {
    $owner = auditOwner();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(AUDIT_URL)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $seed = function () use ($owner): void {
        costChange($owner);
        $role = RoleModel::findByName(Role::Viewer->value, 'web');
        audit(['user_id' => $owner->id, 'action' => AuditAction::RolePermissionsChanged, 'model_type' => $role->getMorphClass(), 'model_id' => $role->id, 'before' => ['role' => 'viewer', 'removed' => []], 'after' => ['role' => 'viewer', 'added' => ['seo.view']]]);
        $staff = User::factory()->create();
        audit(['user_id' => $owner->id, 'action' => AuditAction::RoleAssigned, 'model_type' => $staff->getMorphClass(), 'model_id' => $staff->id, 'before' => ['roles' => []], 'after' => ['roles' => ['viewer'], 'created' => true]]);
    };

    $seed();
    $count(); // warm-up: the first request also loads the session user and the permission cache
    $few = $count();
    $seed();
    $seed();
    $seed();

    expect($count())->toBe($few)
        ->and($this->getJson(AUDIT_URL)->json('data.*.kind'))->toContain('staff_added');
});

it('keeps a deleted subject as its type and id, unlabelled', function (): void {
    $owner = auditOwner();
    audit(['user_id' => $owner->id, 'action' => AuditAction::ShipmentRiderRecorded, 'model_type' => 'App\Domain\Fulfilment\Models\Shipment', 'model_id' => 999_999, 'before' => ['name' => null, 'phone' => null], 'after' => ['name' => 'Karim', 'phone' => '01712*****8', 'source' => 'manual']]);

    $row = $this->getJson(AUDIT_URL)->assertOk()->json('data.0');
    expect($row['subject'])->toBe(['type' => 'shipment', 'id' => 999_999, 'label' => null])
        ->and($row['area'])->toBe('fulfilment')
        ->and($row['kind'])->toBe('rider_recorded');
});

it('exports the filtered rows as CSV with the masked IP', function (): void {
    $owner = auditOwner();
    costChange($owner);
    audit(['action' => AuditAction::CredentialChanged, 'before' => ['scope' => 'tracking'], 'after' => ['scope' => 'tracking', 'ga4_id' => 'G-1']]);

    $response = $this->get(AUDIT_URL.'/export?filter[area]=catalog', ['Accept' => 'application/json'])->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    $csv = (string) file_get_contents($response->baseResponse->getFile()->getPathname());
    expect($csv)->toContain('cost_changed')
        ->toContain('Brick Box 500 / Classic')
        ->toContain('cost_price: 1050.00 → 1120.00')
        ->toContain('103.108.xx.xx')
        ->toContain('Chrome · macOS')
        ->not->toContain('140.21')
        ->not->toContain('tracking_settings');

    $this->get(AUDIT_URL.'/export?filter[nope]=1', ['Accept' => 'application/json'])->assertStatus(400);
});

it('guards GET /audit with audit.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(AUDIT_URL));
})->with(RoleMatrix::dataset(Permission::AuditView));

it('guards GET /audit/export with audit.view + reports.export', function (Role $role): void {
    $allowed = RoleMatrix::allows($role, Permission::AuditView) && RoleMatrix::allows($role, Permission::ReportsExport);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get(AUDIT_URL.'/export', ['Accept' => 'application/json']));
})->with(array_map(static fn (Role $r): array => [$r], Role::cases()));
