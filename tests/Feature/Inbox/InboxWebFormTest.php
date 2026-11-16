<?php

declare(strict_types=1);

use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\ReplyState;
use App\Domain\Inbox\Jobs\SendInboxMessage;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Services\InboxChannels;
use App\Domain\Inbox\Services\ReplyWindow;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Inbox\InboxFixtures;

/*
 * The `web_form` channel (storefront Contact page, 2026-11-16) is inbound only: the reply state
 * is always `closed`, so a reply, a template or a quick reply is refused and nothing is queued;
 * an internal note still works; the send job and the driver never "send" on it.
 */

const WEB_INBOX = '/api/v1/admin/inbox/conversations/';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
});

function webFormStaff(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

function webFormConversation(): Conversation
{
    return InboxFixtures::conversation([
        'channel' => InboxChannel::WebForm,
        'external_thread_id' => 'web:+8801841523907',
        'last_inbound_at' => now()->subMinutes(2),
    ]);
}

it('is always closed for replies, however recent the message', function (): void {
    $c = webFormConversation();

    expect(app(ReplyWindow::class)->for($c)->state)->toBe(ReplyState::Closed)
        ->and($c->windowOpen())->toBeFalse();
});

it('shows the conversation with reply state closed and the web_form channel', function (): void {
    webFormStaff();
    $c = webFormConversation();

    $this->getJson(WEB_INBOX.$c->id)->assertOk()
        ->assertJsonPath('channel', 'web_form')
        ->assertJsonPath('reply.state', 'closed')
        ->assertJsonPath('window.applies', false)
        ->assertJsonPath('window.open', false);

    $this->getJson('/api/v1/admin/inbox/conversations?filter[channel]=web_form')->assertOk()->assertJsonPath('data.0.id', $c->id);
});

it('refuses a reply with 409 and queues nothing', function (): void {
    Queue::fake();
    webFormStaff();
    $c = webFormConversation();

    $this->postJson(WEB_INBOX.$c->id.'/messages', ['body' => 'Hello, we will call you.'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');

    Queue::assertNothingPushed();
    expect(ConversationMessage::query()->count())->toBe(0)
        ->and($c->refresh()->needs_human)->toBeTrue();
});

it('refuses a WhatsApp template on the web form', function (): void {
    Queue::fake();
    webFormStaff();
    $c = webFormConversation();

    $this->postJson(WEB_INBOX.$c->id.'/template', ['template' => 'order_confirmed', 'language' => 'bn', 'parameters' => ['TMB-26-00482', '2-3 দিন']])
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

it('accepts an internal note', function (): void {
    Queue::fake();
    webFormStaff();
    $c = webFormConversation();

    $this->postJson(WEB_INBOX.$c->id.'/notes', ['body' => 'Called her back on WhatsApp.'])
        ->assertStatus(201)
        ->assertJsonPath('kind', 'note');

    Queue::assertNothingPushed();
});

it('never sends a queued reply on the web form, even if one reaches the job', function (): void {
    Log::spy();
    $c = webFormConversation();
    $m = ConversationMessage::query()->create(['conversation_id' => $c->id, 'channel' => InboxChannel::WebForm, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Text, 'body' => 'hi', 'delivery_status' => DeliveryStatus::Queued]);

    SendInboxMessage::dispatchSync($m->id);

    expect($m->refresh()->delivery_status)->toBe(DeliveryStatus::Failed);
    Log::shouldNotHaveReceived('info', ['inbox.log_driver', Mockery::any()]);
});

it('has a driver that refuses every send and no webhook receiver', function (): void {
    $channels = app(InboxChannels::class);

    expect($channels->sender(InboxChannel::WebForm)->name())->toBe('none')
        ->and($channels->receiver(InboxChannel::WebForm))->toBeNull();

    $row = collect($channels->summary())->firstWhere('channel', 'web_form');
    expect($row)->toMatchArray(['driver' => 'none', 'verified' => false, 'webhook_ready' => false, 'send_ready' => false]);

    $this->postJson('/api/v1/webhooks/inbox/web_form', [])->assertNotFound();
});
