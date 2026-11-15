<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Jobs\SendInboxMessage;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Services\ReplyWindow;
use App\Domain\Notification\Actions\SyncDefaultTemplates;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Inbox\InboxFixtures;
use Tests\Support\RoleMatrix;

/*
 * Restarting a closed conversation (P10): WhatsApp approved utility templates after the
 * 24-hour window, Messenger's HUMAN_AGENT tag from 24 h to 7 days behind the
 * `inbox.messenger.human_agent` switch, and the `reply` state the composer reads. Meta is
 * never called for real: Http::fake with payloads written from Meta's published docs.
 */

const TPL_INBOX = '/api/v1/admin/inbox/conversations/';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
    config(['inbox.messenger.human_agent' => false]);
    app(SyncDefaultTemplates::class)->handle();
});

function templateStaff(Role $role = Role::SuperAdmin): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    auth()->forgetGuards();
    test()->actingAs($user, 'web');

    return $user;
}

function approveWhatsApp(string $key, WhatsAppApprovalStatus $status = WhatsAppApprovalStatus::Approved): NotificationTemplate
{
    $template = NotificationTemplate::for($key, NotificationChannel::WhatsApp);
    expect($template)->not->toBeNull();
    $template->whatsapp_status = $status;
    $template->save();

    return $template;
}

function closedWhatsApp(array $attributes = []): App\Domain\Inbox\Models\Conversation
{
    return InboxFixtures::conversation(['last_inbound_at' => now()->subHours(30), ...$attributes]);
}

function messenger(int $hoursAgo): App\Domain\Inbox\Models\Conversation
{
    return InboxFixtures::conversation(['channel' => InboxChannel::Messenger, 'external_thread_id' => '2468013579'.random_int(100, 999), 'contact_phone' => null, 'last_inbound_at' => now()->subHours($hoursAgo)]);
}

function humanAgentSwitch(bool $on): void
{
    Setting::put(ReplyWindow::HUMAN_AGENT_SETTING, $on, SettingGroup::Notification);
}

describe('role matrix', function (): void {
    it('guards the template routes with inbox.reply', function (): void {
        Queue::fake();
        approveWhatsApp('order.confirmed');
        $c = closedWhatsApp();

        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->getJson(TPL_INBOX.$c->id.'/templates'));
        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->postJson(TPL_INBOX.$c->id.'/template', [
            'template' => 'order_confirmed', 'language' => 'bn_BD', 'parameters' => ['TMB-26-00482', '2-3 days'],
        ]), 202);
    });
});

describe('reply state', function (): void {
    it('is open inside the window and template_only after it on WhatsApp', function (): void {
        templateStaff();
        $open = InboxFixtures::conversation();
        $closed = closedWhatsApp();

        $this->getJson(TPL_INBOX.$open->id)->assertOk()->assertJsonPath('reply.state', 'open')
            ->assertJsonPath('reply.window_closes_at', $open->windowExpiresAt()?->toIso8601String());
        $this->getJson(TPL_INBOX.$closed->id)->assertOk()->assertJsonPath('reply.state', 'template_only')
            ->assertJsonPath('reply.window_closes_at', $closed->windowExpiresAt()?->toIso8601String());
        $this->getJson('/api/v1/admin/inbox/conversations')->assertOk()->assertJsonPath('data.0.reply.state', fn (string $s): bool => in_array($s, ['open', 'template_only'], true));
    });

    it('is open on live chat whatever the age', function (): void {
        templateStaff();
        $c = InboxFixtures::conversation(['channel' => InboxChannel::LiveChat, 'last_inbound_at' => now()->subDays(30)]);

        $this->getJson(TPL_INBOX.$c->id)->assertJsonPath('reply.state', 'open')->assertJsonPath('reply.window_closes_at', null);
    });

    it('gives Messenger the human-agent period only with the switch on, and never past 7 days', function (): void {
        templateStaff();
        $recent = messenger(2);
        $day3 = messenger(72);
        $day8 = messenger(24 * 8);

        $this->getJson(TPL_INBOX.$recent->id)->assertJsonPath('reply.state', 'open');
        $this->getJson(TPL_INBOX.$day3->id)->assertJsonPath('reply.state', 'closed');

        humanAgentSwitch(true);
        $this->getJson(TPL_INBOX.$recent->id)->assertJsonPath('reply.state', 'open');
        $this->getJson(TPL_INBOX.$day3->id)->assertJsonPath('reply.state', 'human_agent')
            ->assertJsonPath('reply.window_closes_at', $day3->last_inbound_at?->copy()->addDays(7)->toIso8601String());
        $this->getJson(TPL_INBOX.$day8->id)->assertJsonPath('reply.state', 'closed');
    });

    it('falls back to config when no setting is saved, and the saved setting wins', function (): void {
        templateStaff();
        $c = messenger(30);

        config(['inbox.messenger.human_agent' => true]);
        $this->getJson(TPL_INBOX.$c->id)->assertJsonPath('reply.state', 'human_agent');

        humanAgentSwitch(false);
        $this->getJson(TPL_INBOX.$c->id)->assertJsonPath('reply.state', 'closed');
    });
});

describe('listing templates', function (): void {
    it('lists only approved, active, utility WhatsApp templates with a Meta name, in the customer language', function (): void {
        templateStaff();
        approveWhatsApp('order.confirmed');
        approveWhatsApp('order.shipped', WhatsAppApprovalStatus::Submitted);
        approveWhatsApp('review.request'); // marketing: never offered
        approveWhatsApp('order.delivered')->forceFill(['is_active' => false])->save();
        approveWhatsApp('order.cancelled')->forceFill(['archived_at' => now()])->save();
        approveWhatsApp('order.placed')->forceFill(['provider_template' => null])->save();
        config(['notifications.hotline' => '09600111222']);
        $c = closedWhatsApp();

        $response = $this->getJson(TPL_INBOX.$c->id.'/templates')->assertOk();

        expect(array_column($response->json('data'), 'template'))->toBe(['order_confirmed']);
        $response->assertJsonPath('meta.locale', 'bn')
            ->assertJsonPath('data.0.key', 'order.confirmed')
            ->assertJsonPath('data.0.language', 'bn_BD')
            ->assertJsonPath('data.0.parameters.0.position', 1)
            ->assertJsonPath('data.0.parameters.0.name', 'order_number')
            ->assertJsonPath('data.0.parameters.1.name', 'date_range');
        $body = (string) $response->json('data.0.body');
        expect($body)->toContain('*{{1}}*')->toContain('{{2}}')->toContain('09600111222')->not->toContain('{hotline}');
    });

    it('lists in English for an English-speaking customer', function (): void {
        templateStaff();
        approveWhatsApp('order.confirmed');
        $customer = Customer::factory()->create(['preferred_language' => 'en']);
        $c = closedWhatsApp(['customer_id' => $customer->id]);

        $this->getJson(TPL_INBOX.$c->id.'/templates')->assertOk()
            ->assertJsonPath('meta.locale', 'en')
            ->assertJsonPath('data.0.language', 'en')
            ->assertJsonPath('data.0.body', fn (string $b): bool => str_starts_with($b, 'Your order *{{1}}* is confirmed'));
    });

    it('never offers an OTP template, and offers nothing on Messenger', function (): void {
        templateStaff();
        approveWhatsApp('order.confirmed');
        // A hand-edited security row must still never be offered (rule 32b).
        NotificationTemplate::query()->create([
            'key' => 'otp.login', 'channel' => NotificationChannel::WhatsApp, 'category' => 'security', 'body_en' => 'Code {code}', 'body_bn' => 'কোড {code}',
            'provider_template' => 'otp_login', 'whatsapp_status' => WhatsAppApprovalStatus::Approved, 'is_active' => true,
        ]);

        $wa = $this->getJson(TPL_INBOX.closedWhatsApp()->id.'/templates')->assertOk()->json('data');
        expect(array_column($wa, 'template'))->not->toContain('otp_login');
        $this->getJson(TPL_INBOX.messenger(30)->id.'/templates')->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuses any query parameter', function (): void {
        templateStaff();
        $this->getJson(TPL_INBOX.closedWhatsApp()->id.'/templates?language=en')->assertStatus(400);
    });
});

describe('sending a template', function (): void {
    it('records it like a reply with the rendered preview and queues the send after commit', function (): void {
        Queue::fake();
        $me = templateStaff(Role::OrderOperator);
        approveWhatsApp('order.confirmed');
        $c = closedWhatsApp();

        $this->postJson(TPL_INBOX.$c->id.'/template', ['template' => 'order_confirmed', 'language' => 'bn_BD', 'parameters' => ['TMB-26-00482', ' 2-3 দিন ']])
            ->assertStatus(202)
            ->assertJsonPath('kind', 'template')
            ->assertJsonPath('direction', 'out')
            ->assertJsonPath('delivery_status', 'queued')
            ->assertJsonPath('template.name', 'order_confirmed')
            ->assertJsonPath('template.language', 'bn_BD')
            ->assertJsonPath('sender.id', $me->id)
            ->assertJsonPath('body', fn (string $b): bool => str_contains($b, '*TMB-26-00482*') && str_contains($b, '2-3 দিন') && ! str_contains($b, '{{'));

        Queue::assertPushed(SendInboxMessage::class, fn (SendInboxMessage $job): bool => $job->afterCommit === true);
        $message = ConversationMessage::query()->sole();
        expect($message->meta)->toMatchArray(['template' => 'order_confirmed', 'language' => 'bn_BD', 'parameters' => ['TMB-26-00482', '2-3 দিন']]);
        $c->refresh();
        expect($c->needs_human)->toBeFalse()->and($c->last_message_direction)->toBe('out')->and($c->last_message_preview)->toContain('TMB-26-00482');
    });

    it('refuses while the window is open, and on Messenger, queueing nothing', function (): void {
        Queue::fake();
        templateStaff();
        approveWhatsApp('order.confirmed');
        $body = ['template' => 'order_confirmed', 'language' => 'bn_BD', 'parameters' => ['TMB-26-00482', '2-3 days']];

        $this->postJson(TPL_INBOX.InboxFixtures::conversation()->id.'/template', $body)->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');
        $this->postJson(TPL_INBOX.messenger(30)->id.'/template', $body)->assertStatus(409);

        Queue::assertNothingPushed();
        expect(ConversationMessage::query()->count())->toBe(0);
    });

    it('refuses a template that is not approved, not utility, or with the wrong values', function (array $body, string $field): void {
        Queue::fake();
        templateStaff();
        approveWhatsApp('order.confirmed');
        approveWhatsApp('order.shipped', WhatsAppApprovalStatus::Draft);
        approveWhatsApp('review.request');

        $this->postJson(TPL_INBOX.closedWhatsApp()->id.'/template', $body)->assertStatus(422)->assertJsonPath('errors.0.field', $field);
        Queue::assertNothingPushed();
    })->with([
        'draft' => [['template' => 'order_shipped', 'language' => 'bn_BD', 'parameters' => ['a', 'b', 'c', 'd']], 'template'],
        'marketing' => [['template' => 'review_request', 'language' => 'bn_BD', 'parameters' => ['a', 'b', 'c']], 'template'],
        'unknown' => [['template' => 'nope', 'language' => 'en', 'parameters' => []], 'template'],
        'language' => [['template' => 'order_confirmed', 'language' => 'fr', 'parameters' => ['a', 'b']], 'language'],
        'too few' => [['template' => 'order_confirmed', 'language' => 'en', 'parameters' => ['a']], 'parameters'],
        'newline' => [['template' => 'order_confirmed', 'language' => 'en', 'parameters' => ["a\nb", 'c']], 'parameters.0'],
        'empty' => [['template' => 'order_confirmed', 'language' => 'en', 'parameters' => ['', 'c']], 'parameters.0'],
    ]);

    it('sends Meta a template message outside any transaction, then applies delivery statuses', function (): void {
        config(['inbox.whatsapp.driver' => 'cloud', 'inbox.whatsapp.phone_number_id' => '106540352242922', 'inbox.whatsapp.access_token' => 'EAAG-TEST-TOKEN']);
        templateStaff();
        approveWhatsApp('order.confirmed');
        $c = closedWhatsApp(['external_thread_id' => '8801841523907']);
        $baseline = DB::transactionLevel();
        $levels = [];
        Http::fake(['graph.facebook.com/*' => function () use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.TPL0001', 'message_status' => 'accepted']]]);
        }]);

        $id = $this->postJson(TPL_INBOX.$c->id.'/template', ['template' => 'order_confirmed', 'language' => 'en', 'parameters' => ['TMB-26-00482', '2-3 days']])->assertStatus(202)->json('id');

        expect($levels)->toBe([$baseline]);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v27.0/106540352242922/messages'
            && $r['messaging_product'] === 'whatsapp' && $r['to'] === '8801841523907' && $r['type'] === 'template'
            && $r['template'] === [
                'name' => 'order_confirmed',
                'language' => ['code' => 'en'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'TMB-26-00482'], ['type' => 'text', 'text' => '2-3 days']]]],
            ]
            && ! isset($r['text']));
        $message = ConversationMessage::query()->findOrFail($id);
        expect($message->delivery_status)->toBe(DeliveryStatus::Sent)->and($message->provider_message_id)->toBe('wamid.TPL0001');

        InboxFixtures::post($this, 'whatsapp', InboxFixtures::body('whatsapp-status', ['__WAMID__' => 'wamid.TPL0001']))->assertOk();
        expect($message->refresh()->delivery_status)->toBe(DeliveryStatus::Read);
    });

    it('fails a queued template whose approval was withdrawn, without calling Meta', function (): void {
        config(['inbox.whatsapp.driver' => 'cloud', 'inbox.whatsapp.phone_number_id' => '1', 'inbox.whatsapp.access_token' => 't']);
        Http::fake();
        Queue::fake();
        templateStaff();
        $template = approveWhatsApp('order.confirmed');
        $c = closedWhatsApp();
        $id = $this->postJson(TPL_INBOX.$c->id.'/template', ['template' => 'order_confirmed', 'language' => 'en', 'parameters' => ['TMB-26-00482', '2-3 days']])->assertStatus(202)->json('id');

        $template->whatsapp_status = WhatsAppApprovalStatus::Draft;
        $template->save();
        app()->call([new SendInboxMessage($id), 'handle']);

        Http::assertNothingSent();
        $m = ConversationMessage::query()->findOrFail($id);
        expect($m->delivery_status)->toBe(DeliveryStatus::Failed)->and($m->failure_code)->toBe('template_unavailable');
    });

    it('never sends a free-form text through the template path, nor a template once the window reopened', function (): void {
        config(['inbox.whatsapp.driver' => 'cloud', 'inbox.whatsapp.phone_number_id' => '1', 'inbox.whatsapp.access_token' => 't']);
        Http::fake();
        approveWhatsApp('order.confirmed');
        $c = InboxFixtures::conversation(); // window open again
        $m = ConversationMessage::query()->create([
            'conversation_id' => $c->id, 'channel' => InboxChannel::WhatsApp, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Template,
            'body' => 'x', 'meta' => ['template' => 'order_confirmed', 'language' => 'en', 'parameters' => ['a', 'b']], 'delivery_status' => DeliveryStatus::Queued,
        ]);

        app()->call([new SendInboxMessage($m->id), 'handle']);

        Http::assertNothingSent();
        expect($m->refresh()->failure_code)->toBe('template_not_allowed');
    });
});

describe('Messenger human agent', function (): void {
    it('refuses a reply between 24 hours and 7 days while the switch is off', function (): void {
        Queue::fake();
        templateStaff();

        $this->postJson(TPL_INBOX.messenger(30)->id.'/messages', ['body' => 'Apa, ki khobor?'])->assertStatus(409);
        Queue::assertNothingPushed();
    });

    it('sends a tagged HUMAN_AGENT reply with the switch on', function (): void {
        config(['inbox.messenger.driver' => 'messenger', 'inbox.messenger.page_id' => '1029384756', 'inbox.messenger.page_access_token' => 'PAGE-TEST-TOKEN']);
        humanAgentSwitch(true);
        templateStaff();
        $c = messenger(50);
        Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => $c->external_thread_id, 'message_id' => 'm_HUMAN0001'])]);

        $this->postJson(TPL_INBOX.$c->id.'/messages', ['body' => 'Sorry for the wait — your parcel left today.'])
            ->assertStatus(202)
            ->assertJsonPath('tag', 'HUMAN_AGENT');

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v27.0/1029384756/messages'
            && $r['messaging_type'] === 'MESSAGE_TAG' && $r['tag'] === 'HUMAN_AGENT'
            && $r['recipient'] === ['id' => $c->external_thread_id] && $r['message'] === ['text' => 'Sorry for the wait — your parcel left today.']);
        expect(ConversationMessage::query()->sole()->delivery_status)->toBe(DeliveryStatus::Sent);
    });

    it('sends an ordinary RESPONSE inside 24 hours even with the switch on', function (): void {
        config(['inbox.messenger.driver' => 'messenger', 'inbox.messenger.page_id' => '1029384756', 'inbox.messenger.page_access_token' => 'PAGE-TEST-TOKEN']);
        humanAgentSwitch(true);
        templateStaff();
        $c = messenger(2);
        Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => $c->external_thread_id, 'message_id' => 'm_RESP0001'])]);

        $this->postJson(TPL_INBOX.$c->id.'/messages', ['body' => 'Hi'])->assertStatus(202)->assertJsonPath('tag', null);

        Http::assertSent(fn (HttpRequest $r): bool => $r['messaging_type'] === 'RESPONSE' && ! isset($r['tag']));
    });

    it('fails a queued tagged reply when the switch went off before the job ran, without calling Meta', function (): void {
        config(['inbox.messenger.driver' => 'messenger', 'inbox.messenger.page_id' => '1', 'inbox.messenger.page_access_token' => 't']);
        Http::fake();
        Queue::fake();
        humanAgentSwitch(true);
        templateStaff();
        $id = $this->postJson(TPL_INBOX.messenger(30)->id.'/messages', ['body' => 'Hello'])->assertStatus(202)->json('id');

        humanAgentSwitch(false);
        app()->call([new SendInboxMessage($id), 'handle']);

        Http::assertNothingSent();
        expect(ConversationMessage::query()->findOrFail($id)->failure_code)->toBe('outside_window');
    });

    it('refuses past 7 days even with the switch on', function (): void {
        Queue::fake();
        humanAgentSwitch(true);
        templateStaff();

        $this->postJson(TPL_INBOX.messenger(24 * 7 + 1)->id.'/messages', ['body' => 'Hello'])->assertStatus(409);
        Queue::assertNothingPushed();
    });
});
