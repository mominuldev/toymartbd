<?php

declare(strict_types=1);

use App\Domain\Inbox\Events\InboxConversationChanged;
use App\Domain\Inbox\Models\Conversation;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Inbox\InboxFixtures;
use Tests\Support\RoleMatrix;

/*
 * Inbox live updates (Laravel Reverb): every conversation or message change raises
 * InboxConversationChanged on the private `inbox` channel — a signal with the conversation id only,
 * after commit. POST /broadcasting/auth signs the subscription for inbox.view holders only (rule 21).
 */

const BROADCAST_AUTH = '/api/v1/admin/broadcasting/auth';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
});

/** Swap the test suite's `null` broadcaster for Reverb (fake keys) so channel auth really runs. */
function useReverbForChannelAuth(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => 'localhost',
    ]);
    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php');
}

function liveUpdatesViewer(Role $role = Role::SuperAdmin): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    auth()->forgetGuards();
    test()->actingAs($user, 'web');

    return $user;
}

it('signs the inbox channel for inbox.view only', function (): void {
    useReverbForChannelAuth();

    RoleMatrix::assert($this, Permission::InboxView, fn () => $this->postJson(BROADCAST_AUTH, [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-inbox',
    ]));
});

it('returns a Pusher-protocol signature for the inbox channel', function (): void {
    useReverbForChannelAuth();
    liveUpdatesViewer();

    $auth = $this->postJson(BROADCAST_AUTH, ['socket_id' => '1234.5678', 'channel_name' => 'private-inbox'])
        ->assertOk()
        ->json('auth');

    $expected = 'test-key:'.hash_hmac('sha256', '1234.5678:private-inbox', 'test-secret');
    expect($auth)->toBe($expected);
});

it('refuses a channel outside the allowlist and a malformed socket id', function (array $body, string $field): void {
    useReverbForChannelAuth();
    liveUpdatesViewer();

    $this->postJson(BROADCAST_AUTH, $body)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', $field);
})->with([
    'another channel' => [['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.1'], 'channel_name'],
    'bad socket id' => [['socket_id' => 'abc', 'channel_name' => 'private-inbox'], 'socket_id'],
]);

it('broadcasts the conversation id only, on the private inbox channel, on the notify queue', function (): void {
    $event = new InboxConversationChanged(42);

    expect($event->broadcastOn())->toEqual([new PrivateChannel('inbox')])
        ->and($event->broadcastAs())->toBe('conversation.changed')
        ->and($event->broadcastWith())->toBe(['conversation_id' => 42])
        ->and($event->broadcastQueue())->toBe('notify')
        ->and($event->broadcastWhen())->toBeFalse(); // the suite's `null` broadcaster

    config(['broadcasting.default' => 'reverb']);
    expect($event->broadcastWhen())->toBeTrue();
});

it('raises the change when a WhatsApp message arrives', function (): void {
    Event::fake([InboxConversationChanged::class]);

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-text'))->assertOk();

    $id = Conversation::query()->sole()->id;
    Event::assertDispatched(InboxConversationChanged::class, fn (InboxConversationChanged $e): bool => $e->conversationId === $id);
});

it('raises the change on a staff reply, a note, read, assign and resolve', function (string $path, array $body): void {
    Queue::fake();
    $viewer = liveUpdatesViewer();
    $conversation = InboxFixtures::conversation(['unread_count' => 2, 'needs_human' => true]);
    $body = array_map(static fn (mixed $v): mixed => $v === 'viewer' ? $viewer->id : $v, $body);
    Event::fake([InboxConversationChanged::class]);

    $this->postJson("/api/v1/admin/inbox/conversations/{$conversation->id}/{$path}", $body)->assertSuccessful();

    Event::assertDispatched(InboxConversationChanged::class, fn (InboxConversationChanged $e): bool => $e->conversationId === $conversation->id);
})->with([
    'reply' => ['messages', ['body' => 'Kal pouchabe.']],
    'note' => ['notes', ['body' => 'Called the customer.']],
    'read' => ['read', []],
    'assign' => ['assign', ['user_id' => 'viewer']],
    'resolve' => ['resolve', []],
]);

it('raises the change when a conversation is removed, so the lists and the badge drop it', function (): void {
    $conversation = InboxFixtures::conversation(['unread_count' => 2, 'needs_human' => true]);
    Event::fake([InboxConversationChanged::class]);

    $conversation->delete();

    Event::assertDispatched(InboxConversationChanged::class, fn (InboxConversationChanged $e): bool => $e->conversationId === $conversation->id);
});

it('stays quiet on a save that changes nothing', function (): void {
    $conversation = InboxFixtures::conversation();
    Event::fake([InboxConversationChanged::class]);

    $conversation->save();

    Event::assertNotDispatched(InboxConversationChanged::class);
});

it('waits for the transaction to commit, and never fires for a rolled-back write', function (): void {
    $conversation = InboxFixtures::conversation();
    Event::fake([InboxConversationChanged::class]);

    DB::beginTransaction();
    $conversation->update(['unread_count' => 5]);
    Event::assertNotDispatched(InboxConversationChanged::class);
    DB::rollBack();

    Event::assertNotDispatched(InboxConversationChanged::class);
});
