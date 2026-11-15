<?php

declare(strict_types=1);

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * Message templates › "Sync from Meta": reads GET /{waba-id}/message_templates (Graph API,
 * hand-authored fixtures from Meta's reference — no WhatsApp Business account exists) and
 * records each template's status through the same Action as a manual change.
 */

const SYNC_URL = '/api/v1/admin/notifications/templates/whatsapp/sync';
const SYNC_WABA = '102290129340398';
const SYNC_TOKEN = 'EAAG-sync-token-TEST-0099';
const SYNC_META = 'https://graph.facebook.com/v27.0/102290129340398/message_templates*';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'inbox.graph_base_url' => 'https://graph.facebook.com',
        'inbox.graph_version' => 'v27.0',
        'inbox.whatsapp.business_account_id' => SYNC_WABA,
        'inbox.whatsapp.access_token' => SYNC_TOKEN,
    ]);
});

function syncAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** Two WhatsApp templates with known provider names; every other one without a name. */
function syncTemplates(): array
{
    NotificationTemplate::query()->where('channel', NotificationChannel::WhatsApp->value)->update(['provider_template' => null]);
    $placed = NotificationTemplate::for('order.placed', NotificationChannel::WhatsApp);
    $shipped = NotificationTemplate::for('order.shipped', NotificationChannel::WhatsApp);
    $delivered = NotificationTemplate::for('order.delivered', NotificationChannel::WhatsApp);
    $placed->update(['provider_template' => 'order_placed', 'whatsapp_status' => WhatsAppApprovalStatus::Submitted]);
    $shipped->update(['provider_template' => 'order_shipped', 'whatsapp_status' => WhatsAppApprovalStatus::Approved]);
    $delivered->update(['provider_template' => 'order_delivered_v2', 'whatsapp_status' => WhatsAppApprovalStatus::Draft]);

    return [$placed, $shipped, $delivered];
}

/** @param  list<array<string, string>>  $data */
function syncPage(array $data, ?string $next = null, ?string $after = null): array
{
    $paging = ['cursors' => ['before' => 'QVFIUBEFORE', 'after' => $after ?? 'QVFIUAFTER']];
    if ($next !== null) {
        $paging['next'] = $next;
    }

    return ['data' => $data, 'paging' => $paging];
}

it('maps Meta statuses per language, follows paging.next, and reports the counts', function (): void {
    $admin = syncAdmin();
    [$placed, $shipped, $delivered] = syncTemplates();
    $next = 'https://graph.facebook.com/v27.0/'.SYNC_WABA.'/message_templates?fields=name,language,status,category&limit=100&after=QVFIUAFTER';

    Http::fake([
        SYNC_META => Http::sequence()
            ->push(syncPage([
                ['name' => 'order_placed', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'id' => '1'],
                ['name' => 'order_placed', 'language' => 'bn', 'status' => 'APPROVED', 'category' => 'UTILITY', 'id' => '2'],
                ['name' => 'order_shipped', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'id' => '3'],
            ], next: $next))
            ->push(syncPage([
                // Bangla paused: the template cannot go out in Bangla, so it is paused overall.
                ['name' => 'order_shipped', 'language' => 'bn_BD', 'status' => 'PAUSED', 'category' => 'UTILITY', 'id' => '4'],
                ['name' => 'eid_offer', 'language' => 'bn', 'status' => 'REJECTED', 'category' => 'MARKETING', 'id' => '5'],
            ])),
    ]);

    $this->postJson(SYNC_URL)->assertOk()->assertJson([
        'matched' => 2,
        'updated' => 2,
        'unknown' => 1,
        'missing' => 1,
        'unknown_names' => ['eid_offer'],
        'missing_keys' => ['order.delivered'],
    ]);

    expect($placed->refresh()->whatsapp_status)->toBe(WhatsAppApprovalStatus::Approved)
        ->and($placed->updated_by)->toBe($admin->id)
        ->and($shipped->refresh()->whatsapp_status)->toBe(WhatsAppApprovalStatus::Paused)
        ->and($delivered->refresh()->whatsapp_status)->toBe(WhatsAppApprovalStatus::Draft);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.SYNC_TOKEN)
        && str_contains($r->url(), 'fields=name%2Clanguage%2Cstatus%2Ccategory') && str_contains($r->url(), 'limit=100'));
});

it('treats a language Meta does not have as draft, and leaves an unknown Meta status alone', function (): void {
    syncAdmin();
    [$placed, $shipped] = syncTemplates();
    Http::fake([SYNC_META => Http::response(syncPage([
        ['name' => 'order_placed', 'language' => 'en', 'status' => 'APPROVED'],
        ['name' => 'order_shipped', 'language' => 'en', 'status' => 'SOMETHING_NEW'],
        ['name' => 'order_shipped', 'language' => 'bn', 'status' => 'APPROVED'],
    ]))]);

    $this->postJson(SYNC_URL)->assertOk()->assertJsonPath('matched', 2)->assertJsonPath('updated', 1);

    expect($placed->refresh()->whatsapp_status)->toBe(WhatsAppApprovalStatus::Draft)
        ->and($shipped->refresh()->whatsapp_status)->toBe(WhatsAppApprovalStatus::Approved);
});

it('maps every documented Meta template status', function (string $meta, ?WhatsAppApprovalStatus $ours): void {
    expect(WhatsAppApprovalStatus::fromMeta($meta))->toBe($ours);
})->with([
    ['APPROVED', WhatsAppApprovalStatus::Approved],
    ['PENDING', WhatsAppApprovalStatus::Submitted],
    ['IN_APPEAL', WhatsAppApprovalStatus::Submitted],
    ['REJECTED', WhatsAppApprovalStatus::Rejected],
    ['PAUSED', WhatsAppApprovalStatus::Paused],
    ['DISABLED', WhatsAppApprovalStatus::Disabled],
    ['PENDING_DELETION', WhatsAppApprovalStatus::Disabled],
    ['DELETED', WhatsAppApprovalStatus::Disabled],
    ['ARCHIVED', WhatsAppApprovalStatus::Disabled],
    ['LIMIT_EXCEEDED', WhatsAppApprovalStatus::Disabled],
    ['SOMETHING_NEW', null],
]);

it('never follows a paging.next to another host with the token', function (): void {
    syncAdmin();
    syncTemplates();
    Http::fake([SYNC_META => Http::response(syncPage([['name' => 'order_placed', 'language' => 'en', 'status' => 'APPROVED']], next: 'https://evil.example/steal'))]);

    $this->postJson(SYNC_URL)->assertOk();

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'evil.example'));
});

it('answers 409 without a Business Account id or when Meta refuses it, 503 when unreachable — changing nothing', function (): void {
    syncAdmin();
    [$placed] = syncTemplates();

    config(['inbox.whatsapp.business_account_id' => null]);
    $this->postJson(SYNC_URL)->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');
    Http::assertNothingSent();

    config(['inbox.whatsapp.business_account_id' => SYNC_WABA]);
    Http::fake([SYNC_META => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 401)]);
    $response = $this->postJson(SYNC_URL)->assertStatus(409);
    expect($response->json('detail'))->toContain('meta_190')
        ->and($response->getContent())->not->toContain(SYNC_TOKEN)
        ->and($placed->refresh()->whatsapp_status)->toBe(WhatsAppApprovalStatus::Submitted);

    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 28'));
    $this->postJson(SYNC_URL)->assertStatus(503)->assertJsonPath('type', 'https://toymartbd.com/problems/service-unavailable');
});

it('lets staff record Meta\'s rejected / paused / disabled by hand too', function (): void {
    syncAdmin();
    $wa = NotificationTemplate::for('order.placed', NotificationChannel::WhatsApp);

    $this->putJson("/api/v1/admin/notifications/templates/{$wa->id}/whatsapp-status", ['status' => 'rejected'])->assertOk()
        ->assertJsonPath('whatsapp_status', 'rejected');
});

it('guards POST /notifications/templates/whatsapp/sync with settings.update', function (): void {
    Http::fake([SYNC_META => Http::response(syncPage([]))]);
    RoleMatrix::assert($this, Permission::SettingsUpdate, fn () => $this->postJson(SYNC_URL));
});
