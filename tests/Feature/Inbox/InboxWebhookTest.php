<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\SystemEvent;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Models\InboxWebhookReceipt;
use App\Domain\Inbox\Support\MetaSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Inbox\InboxFixtures;

/*
 * Inbox webhooks (P10): WhatsApp Cloud API and Messenger, against hand-authored fixtures.
 * Signature first, before any parse or write (rule 19); idempotent on the provider message id.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
});

function inboxNothingWritten(): void
{
    expect(InboxWebhookReceipt::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(ConversationMessage::query()->count())->toBe(0);
}

it('refuses a missing, malformed or wrong signature with 401 and writes nothing', function (?string $signature): void {
    $body = InboxFixtures::body('whatsapp-text');

    InboxFixtures::post($this, 'whatsapp', $body, $signature)
        ->assertStatus(401)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');

    inboxNothingWritten();
})->with([
    'missing' => [null],
    'not sha256=' => ['sha1='.str_repeat('a', 40)],
    'not hex' => ['sha256=zz'],
    'wrong secret' => [MetaSignature::sign('{"object":"whatsapp_business_account"}', 'another-secret')],
]);

it('refuses a body that was changed after signing', function (): void {
    $body = InboxFixtures::body('whatsapp-text');
    $signature = MetaSignature::sign($body, InboxFixtures::WA_SECRET);

    InboxFixtures::post($this, 'whatsapp', str_replace('TMB-26-00121', 'TMB-26-99999', $body), $signature)->assertStatus(401);

    inboxNothingWritten();
});

it('refuses every webhook while no app secret is configured', function (): void {
    config(['inbox.whatsapp.app_secret' => null]);
    $body = InboxFixtures::body('whatsapp-text');

    InboxFixtures::post($this, 'whatsapp', $body, MetaSignature::sign($body, ''))->assertStatus(401);

    inboxNothingWritten();
});

it('uses the Messenger app secret for Messenger, not the WhatsApp one', function (): void {
    $body = InboxFixtures::body('messenger-text');

    InboxFixtures::post($this, 'messenger', $body, MetaSignature::sign($body, InboxFixtures::WA_SECRET))->assertStatus(401);

    inboxNothingWritten();
});

it('never logs the body or the phone of a refused webhook', function (): void {
    Log::spy();

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-text'), 'sha256='.str_repeat('0', 64))->assertStatus(401);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $flat = json_encode($context, JSON_THROW_ON_ERROR);

        return $message === 'inbox.webhook.signature_invalid' && ! str_contains($flat, '1841523907') && ! str_contains($flat, 'TMB');
    })->once();
});

it('answers the subscription handshake only with the right verify token', function (): void {
    $this->get('/api/v1/webhooks/inbox/whatsapp?hub.mode=subscribe&hub.verify_token='.InboxFixtures::WA_VERIFY.'&hub.challenge=1158201444')
        ->assertOk()
        ->assertSee('1158201444', false);

    $this->getJson('/api/v1/webhooks/inbox/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1158201444')->assertStatus(403);
    $this->getJson('/api/v1/webhooks/inbox/messenger?hub.mode=subscribe&hub.verify_token='.InboxFixtures::WA_VERIFY.'&hub.challenge=1')->assertStatus(403);
    $this->getJson('/api/v1/webhooks/inbox/messenger?hub.mode=unsubscribe&hub.verify_token='.InboxFixtures::FB_VERIFY.'&hub.challenge=1')->assertStatus(403);
    $this->getJson('/api/v1/webhooks/inbox/live_chat?hub.mode=subscribe')->assertNotFound();
});

it('creates a WhatsApp conversation, links the customer by phone and opens the 24-hour window', function (): void {
    $customer = Customer::factory()->create(['name' => 'Nusrat Jahan', 'phone' => '+8801841523907']);

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-text'))
        ->assertOk()
        ->assertJson(['received' => true, 'replayed' => false]);

    $conversation = Conversation::query()->sole();
    expect($conversation->channel)->toBe(InboxChannel::WhatsApp)
        ->and($conversation->external_thread_id)->toBe('8801841523907')
        ->and($conversation->contact_name)->toBe('Nusrat Jahan')
        ->and($conversation->contact_phone)->toBe('+8801841523907')
        ->and($conversation->customer_id)->toBe($customer->id)
        ->and($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->needs_human)->toBeTrue()
        ->and($conversation->unread_count)->toBe(1)
        ->and($conversation->last_message_preview)->toBe('আমার পার্সেল কোথায়? অর্ডার TMB-26-00121')
        ->and($conversation->last_inbound_at?->getTimestamp())->toBe(1763190480);

    $message = ConversationMessage::query()->sole();
    expect($message->direction)->toBe(MessageDirection::In)
        ->and($message->kind)->toBe(MessageKind::Text)
        ->and($message->provider_message_id)->toStartWith('wamid.')
        ->and($message->body)->toBe('আমার পার্সেল কোথায়? অর্ডার TMB-26-00121');

    expect(InboxWebhookReceipt::query()->sole()->processed_at)->not->toBeNull();
});

it('is idempotent: a re-delivered body or the same wamid in a new body adds nothing', function (): void {
    $body = InboxFixtures::body('whatsapp-text');

    InboxFixtures::post($this, 'whatsapp', $body)->assertOk()->assertJsonPath('replayed', false);
    InboxFixtures::post($this, 'whatsapp', $body)->assertOk()->assertJsonPath('replayed', true);
    // Meta retries can re-wrap the same message in a different envelope.
    InboxFixtures::post($this, 'whatsapp', str_replace('"id": "102290129340398"', '"id": "102290129340399"', $body))->assertOk()->assertJsonPath('replayed', false);

    expect(Conversation::query()->count())->toBe(1)
        ->and(ConversationMessage::query()->count())->toBe(1)
        ->and(Conversation::query()->sole()->unread_count)->toBe(1)
        ->and(InboxWebhookReceipt::query()->count())->toBe(2);
});

it('appends to the same conversation and records a non-text message as unsupported', function (): void {
    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-text'))->assertOk();
    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-image'))->assertOk();

    $conversation = Conversation::query()->sole();
    expect($conversation->unread_count)->toBe(2)
        ->and($conversation->last_message_preview)->toBe('[image]');
    $image = ConversationMessage::query()->where('provider_message_id', 'like', 'wamid.IMAGE%')->sole();
    expect($image->body)->toBeNull()->and($image->meta)->toBe(['type' => 'image']);
});

it('reopens a resolved conversation when the customer writes again', function (): void {
    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-text'))->assertOk();
    Conversation::query()->update(['status' => ConversationStatus::Resolved->value, 'resolved_at' => now(), 'needs_human' => false]);

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-image'))->assertOk();

    expect(Conversation::query()->sole()->status)->toBe(ConversationStatus::Open)
        ->and(ConversationMessage::query()->where('kind', MessageKind::System->value)->sole()->system_event)->toBe(SystemEvent::Reopened);
});

it('applies WhatsApp delivery statuses forward only', function (): void {
    $conversation = InboxFixtures::conversation();
    $message = ConversationMessage::query()->create([
        'conversation_id' => $conversation->id, 'channel' => InboxChannel::WhatsApp, 'direction' => MessageDirection::Out,
        'kind' => MessageKind::Text, 'body' => 'Apa, ami Rina.', 'delivery_status' => DeliveryStatus::Sent,
        'provider_message_id' => 'wamid.OUT0001', 'sent_at' => now(),
    ]);

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status', ['__WAMID__' => 'wamid.OUT0001']))->assertOk();
    $message->refresh();
    expect($message->delivery_status)->toBe(DeliveryStatus::Read)
        ->and($message->read_at?->getTimestamp())->toBe(1763190660)
        ->and($message->delivered_at)->not->toBeNull();

    // A late `failed` after `read` changes nothing.
    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status-failed', ['__WAMID__' => 'wamid.OUT0001']))->assertOk();
    expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Read);
});

it('records a WhatsApp failure with its error code', function (): void {
    $conversation = InboxFixtures::conversation();
    $message = ConversationMessage::query()->create([
        'conversation_id' => $conversation->id, 'channel' => InboxChannel::WhatsApp, 'direction' => MessageDirection::Out,
        'kind' => MessageKind::Text, 'body' => 'Hello', 'delivery_status' => DeliveryStatus::Sent,
        'provider_message_id' => 'wamid.OUT0002', 'sent_at' => now(),
    ]);

    InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status-failed', ['__WAMID__' => 'wamid.OUT0002']))->assertOk();

    $message->refresh();
    expect($message->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($message->failure_code)->toBe('provider_error:131049');
});

it('creates a Messenger conversation by PSID with no phone and no name', function (): void {
    InboxFixtures::post($this, 'messenger', InboxFixtures::body('messenger-text'))->assertOk();

    $conversation = Conversation::query()->sole();
    expect($conversation->channel)->toBe(InboxChannel::Messenger)
        ->and($conversation->external_thread_id)->toBe('6612345678901234')
        ->and($conversation->contact_phone)->toBeNull()
        ->and($conversation->customer_id)->toBeNull()
        ->and($conversation->last_inbound_at?->equalTo(Carbon::createFromTimestampMsUTC(1763190720000)))->toBeTrue();
    expect(ConversationMessage::query()->sole()->provider_message_id)->toBe('m_AbCdEfGh1234567890');
});

it('ignores Messenger echoes and applies the read watermark', function (): void {
    InboxFixtures::post($this, 'messenger', InboxFixtures::body('messenger-text'))->assertOk();
    $conversation = Conversation::query()->sole();
    $sent = ConversationMessage::query()->create([
        'conversation_id' => $conversation->id, 'channel' => InboxChannel::Messenger, 'direction' => MessageDirection::Out,
        'kind' => MessageKind::Text, 'body' => 'Yes, tonight.', 'delivery_status' => DeliveryStatus::Sent,
        'provider_message_id' => 'm_out_1', 'sent_at' => now(),
    ]);

    InboxFixtures::post($this, 'messenger', InboxFixtures::body('messenger-echo-read'))->assertOk();

    expect(ConversationMessage::query()->where('provider_message_id', 'm_echo_0001')->exists())->toBeFalse()
        ->and($sent->refresh()->delivery_status)->toBe(DeliveryStatus::Read);
});
