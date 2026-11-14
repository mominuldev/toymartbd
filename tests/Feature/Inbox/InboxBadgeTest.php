<?php

declare(strict_types=1);

use App\Domain\Inbox\Enums\ConversationStatus;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Inbox\InboxFixtures;
use Tests\Support\RoleMatrix;

/*
 * The Inbox nav badge (owner decision 2026-11-15): GET /inbox/unread-count. Counts OPEN
 * conversations with unread inbound messages or waiting on a staff reply (needs_human),
 * each once. inbox.view; 403 role matrix (rule 21).
 */

const INBOX_BADGE = '/api/v1/admin/inbox/unread-count';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
});

function inboxBadgeViewer(Role $role = Role::SuperAdmin): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    auth()->forgetGuards();
    test()->actingAs($user, 'web');

    return $user;
}

it('is guarded by inbox.view', function (): void {
    InboxFixtures::conversation();

    RoleMatrix::assert($this, Permission::InboxView, fn () => $this->getJson(INBOX_BADGE));
});

it('counts open conversations that are unread or waiting on a reply, each once', function (): void {
    inboxBadgeViewer();

    InboxFixtures::conversation(['unread_count' => 3, 'needs_human' => true]);   // both: counted once
    InboxFixtures::conversation(['unread_count' => 2, 'needs_human' => false]);  // unread only
    InboxFixtures::conversation(['unread_count' => 0, 'needs_human' => true]);   // read, still waiting on us
    InboxFixtures::conversation(['unread_count' => 0, 'needs_human' => false]);  // handled: not counted
    InboxFixtures::conversation(['status' => ConversationStatus::Resolved, 'unread_count' => 4, 'needs_human' => true]); // resolved: never

    $this->getJson(INBOX_BADGE)->assertOk()->assertExactJson(['count' => 3]);
});

it('is zero with an empty inbox', function (): void {
    inboxBadgeViewer();

    $this->getJson(INBOX_BADGE)->assertOk()->assertExactJson(['count' => 0]);
});

it('follows the thread: reading keeps a waiting conversation, a staff reply clears it', function (): void {
    Queue::fake();
    inboxBadgeViewer();
    $c = InboxFixtures::conversation(['unread_count' => 2, 'needs_human' => true]);

    $this->getJson(INBOX_BADGE)->assertExactJson(['count' => 1]);

    $this->postJson("/api/v1/admin/inbox/conversations/{$c->id}/read")->assertOk();
    $this->getJson(INBOX_BADGE)->assertExactJson(['count' => 1]); // read, but nobody has answered

    $this->postJson("/api/v1/admin/inbox/conversations/{$c->id}/messages", ['body' => 'আপনার পার্সেল আজ পৌঁছাবে।'])->assertStatus(202);
    $this->getJson(INBOX_BADGE)->assertExactJson(['count' => 0]);
});

it('drops a conversation once it is resolved', function (): void {
    inboxBadgeViewer();
    $c = InboxFixtures::conversation(['unread_count' => 1, 'needs_human' => true]);

    $this->postJson("/api/v1/admin/inbox/conversations/{$c->id}/resolve")->assertOk();

    $this->getJson(INBOX_BADGE)->assertExactJson(['count' => 0]);
});

it('is the same number for every staff member, not per assignee', function (): void {
    $other = User::factory()->create();
    InboxFixtures::conversation(['assigned_to' => $other->id]);
    InboxFixtures::conversation();

    inboxBadgeViewer(Role::Manager);

    $this->getJson(INBOX_BADGE)->assertOk()->assertExactJson(['count' => 2]);
});
