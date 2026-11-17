<?php

declare(strict_types=1);

use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Models\AiRequest;
use App\Domain\Ai\Services\AiDrivers;
use App\Domain\Customer\Models\Customer;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Feature\Inbox\InboxFixtures;
use Tests\Support\RoleMatrix;

/*
 * Inbox › Suggested reply (Figma 539:83211): POST /inbox/conversations/{id}/ai-draft. The draft
 * is never sent or stored; the prompt carries the thread, a first name and order facts — never
 * the phone number, address lines or payment details.
 */

const AI_DRAFT_PHONE = '+8801712345678';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    InboxFixtures::configure();
    config(['inbox.storefront_url' => 'https://toymartbd.com']);
    $this->fake = AiDrivers::fake();
    Setting::put('ai.key.anthropic', 'sk-ant-test-0000000000000000', SettingGroup::Ai, true);
    $this->seed(DeliverySeeder::class);
});

function aiDraftStaff(Role $role = Role::SuperAdmin): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    auth()->forgetGuards();
    test()->actingAs($user, 'web');

    return $user;
}

/** @return array{0: Conversation, 1: Order} */
function aiDraftThread(string $lastMessage = 'আমার অর্ডার TMB-26-00126 কবে পাব?', InboxChannel $channel = InboxChannel::WhatsApp, ?Carbon\CarbonInterface $lastInbound = null): array
{
    $customer = Customer::factory()->create(['name' => 'Rafi Ahmed', 'phone' => AI_DRAFT_PHONE, 'email' => 'rafi.ahmed@example.com']);
    $order = Order::factory()->create([
        'number' => 'TMB-26-00126', 'customer_id' => $customer->id, 'status' => OrderStatus::Shipped,
        'subtotal' => '6190.00', 'shipping_charge' => '70.00', 'grand_total' => '6260.00', 'due_total' => '6260.00',
        'shipping_phone' => AI_DRAFT_PHONE, 'shipping_district' => 'Dhaka', 'shipping_area' => 'Mirpur',
        'shipping_address' => 'House 7, Road 3, Section 10', 'shipping_landmark' => 'Near Mirpur 10 roundabout',
    ]);
    $provider = CourierProvider::query()->where('code', 'steadfast')->firstOrFail();
    $shipment = Shipment::query()->create([
        'order_id' => $order->id, 'provider_id' => $provider->id, 'number' => 'SHP-26-000126', 'consignment_id' => 'CN-998877',
        'status' => ShipmentStatus::InTransit, 'cod_amount' => '6260.00', 'declared_value' => '6260.00',
    ]);
    CourierTrackingEvent::query()->create([
        'shipment_id' => $shipment->id, 'provider_id' => $provider->id, 'normalised_status' => ShipmentStatus::InTransit,
        'provider_status' => 'in_review', 'occurred_at' => now()->subHours(2), 'received_at' => now()->subHours(2), 'source' => 'webhook',
    ]);

    $at = $lastInbound ?? now()->subMinutes(2);
    $c = InboxFixtures::conversation([
        'channel' => $channel, 'customer_id' => $customer->id, 'contact_name' => 'Rafi Ahmed', 'contact_phone' => AI_DRAFT_PHONE,
        'last_inbound_at' => $at, 'last_message_at' => $at,
    ]);
    ConversationMessage::query()->create(['conversation_id' => $c->id, 'channel' => $channel, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Text, 'body' => 'Thanks for your order!']);
    ConversationMessage::query()->create(['conversation_id' => $c->id, 'channel' => $channel, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Note, 'body' => 'INTERNAL: customer was rude, bKash TrxID 9AB7XYZ']);
    ConversationMessage::query()->create(['conversation_id' => $c->id, 'channel' => $channel, 'direction' => MessageDirection::In, 'kind' => MessageKind::Text, 'body' => $lastMessage]);

    return [$c, $order];
}

function aiDraftUrl(Conversation $c): string
{
    return '/api/v1/admin/inbox/conversations/'.$c->id.'/ai-draft';
}

function aiDraftPrompt(): string
{
    $sent = test()->fake->sent;
    $last = end($sent);

    return $last['prompt']->system."\n".$last['prompt']->user;
}

it('drafts a Bangla reply from the thread and the order facts, and sends nothing', function (): void {
    aiDraftStaff();
    [$c] = aiDraftThread();
    $this->fake->respond(['reply' => 'রাফি ভাই, আপনার অর্ডার TMB-26-00126 Steadfast-এ পথে আছে। ট্র্যাক করতে: toymartbd.com/bn/track']);
    $messages = ConversationMessage::query()->count();

    $json = $this->postJson(aiDraftUrl($c))->assertOk()->json();

    expect($json['text'])->toContain('TMB-26-00126')
        ->and($json['language'])->toBe('bn')
        ->and($json['model'])->toBe('claude-opus-5-5')
        ->and($json['window_open'])->toBeTrue()
        ->and($json['reply_state'])->toBe('open')
        ->and($json['based_on'][0])->toBe('Order TMB-26-00126')
        ->and($json['based_on'][1])->toBe('Steadfast · In transit · 2 h ago');
    expect(ConversationMessage::query()->count())->toBe($messages);
    Queue::assertNothingPushed();
    expect(AiRequest::query()->sole()->feature)->toBe('inbox_reply');

    $prompt = aiDraftPrompt();
    expect($prompt)->toContain('TMB-26-00126')->toContain('Steadfast')->toContain('in transit')->toContain('৳6,260')
        ->toContain('Dhaka')->toContain('"customer_first_name": "Rafi"')->toContain('toymartbd.com/bn/track')
        ->toContain('Bangla');
});

it('never puts the phone number, address lines, email, notes or payment details in the prompt', function (): void {
    aiDraftStaff();
    [$c] = aiDraftThread('Where is my order? Call me on 01712345678 or mail rafi.ahmed@example.com');
    $this->fake->respond(['reply' => 'Hi Rafi, your order TMB-26-00126 is on the way with Steadfast.']);

    $this->postJson(aiDraftUrl($c))->assertOk();

    $prompt = aiDraftPrompt();
    foreach (['01712345678', '1712345678', '+8801712345678', 'rafi.ahmed@example.com', 'House 7', 'Road 3', 'Mirpur', 'roundabout', 'INTERNAL', 'TrxID', '9AB7XYZ', 'CN-998877', 'Ahmed', 'payment_method'] as $secret) {
        expect($prompt)->not->toContain($secret);
    }
    expect($prompt)->toContain('[removed]');
});

it('detects English from the last customer message, and a style forces the language or length', function (): void {
    aiDraftStaff();
    [$c] = aiDraftThread('Is my parcel coming today?');
    $this->fake->respond(['reply' => 'Hi Rafi, it is on the way.'], ['reply' => 'রাফি ভাই, পথে আছে।'], ['reply' => 'On the way.']);

    expect($this->postJson(aiDraftUrl($c))->assertOk()->json('language'))->toBe('en');
    expect(aiDraftPrompt())->toContain('friendly English')->toContain('toymartbd.com/track');

    expect($this->postJson(aiDraftUrl($c), ['style' => 'bangla'])->assertOk()->json('language'))->toBe('bn');
    expect(aiDraftPrompt())->toContain('Bengali script');

    expect($this->postJson(aiDraftUrl($c), ['style' => 'shorter'])->assertOk()->json('language'))->toBe('en');
    expect(aiDraftPrompt())->toContain('At most 2 short sentences');

    $this->postJson(aiDraftUrl($c), ['style' => 'longer'])->assertUnprocessable();
});

it('sends no name at all when sharing first names is off', function (): void {
    Setting::put('ai.share', ['figures' => true, 'products' => true, 'first_names' => false], SettingGroup::Ai);
    aiDraftStaff();
    [$c] = aiDraftThread();
    $this->fake->respond(['reply' => 'আসসালামু আলাইকুম! আপনার অর্ডার TMB-26-00126 পথে আছে।']);

    $this->postJson(aiDraftUrl($c))->assertOk();

    expect(aiDraftPrompt())->not->toContain('Rafi')->toContain('"customer_first_name": null')->toContain('generic greeting');
});

it('still drafts when the WhatsApp window has closed, and says so', function (): void {
    aiDraftStaff();
    [$c] = aiDraftThread('Hello?', InboxChannel::WhatsApp, now()->subHours(30));
    $this->fake->respond(['reply' => 'Hi Rafi, your order is on the way.']);

    $this->postJson(aiDraftUrl($c))->assertOk()
        ->assertJsonPath('window_open', false)
        ->assertJsonPath('reply_state', 'template_only');
});

it('retries once when the draft invents a figure, then refuses with ai-unavailable', function (): void {
    aiDraftStaff();
    [$c] = aiDraftThread();
    $this->fake->respond(['reply' => 'আপনার অর্ডার ১৯ নভেম্বর পৌঁছাবে, মোট 5,999 টাকা।'], ['reply' => 'অর্ডার TMB-26-00126 পথে আছে।']);

    $this->postJson(aiDraftUrl($c))->assertOk()->assertJsonPath('text', 'অর্ডার TMB-26-00126 পথে আছে।');
    expect($this->fake->sent)->toHaveCount(2)->and($this->fake->sent[1]['prompt']->system)->toContain('5,999');

    $this->fake->respond(['reply' => 'Delivery on 21 November, total 7,450.'], ['reply' => 'Delivery on 21 November, total 7,450.']);
    $this->postJson(aiDraftUrl($c))->assertStatus(503)->assertJsonPath('type', fn (string $t) => str_ends_with($t, '/ai-unavailable'));
});

it('answers ai-not-configured without a key, and passes provider errors through as problems', function (): void {
    aiDraftStaff();
    [$c] = aiDraftThread();
    Setting::put('ai.key.anthropic', '', SettingGroup::Ai, true);

    $this->postJson(aiDraftUrl($c))->assertStatus(409)->assertJsonPath('type', fn (string $t) => str_ends_with($t, '/ai-not-configured'));

    Setting::put('ai.key.anthropic', 'sk-ant-test-0000000000000000', SettingGroup::Ai, true);
    $this->fake->respond(new AiException(AiException::RATE_LIMITED, 'Slow down.'));
    $this->postJson(aiDraftUrl($c))->assertStatus(503);
});

it('leaves order facts out for a role without customers.view', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $role = RoleModel::findOrCreate('inbox_ai_only', 'web');
    $role->syncPermissions([Permission::InboxView->value, Permission::InboxReply->value]);
    $user = User::factory()->create()->refresh();
    $user->assignRole($role);
    auth()->forgetGuards();
    $this->actingAs($user, 'web');
    [$c] = aiDraftThread('Where is my parcel?');
    $this->fake->respond(['reply' => 'Hi Rafi, let me check and get back to you.']);

    $this->postJson(aiDraftUrl($c))->assertOk()->assertJsonPath('based_on', []);
    expect(aiDraftPrompt())->not->toContain('Steadfast')->not->toContain('6,260');
});

describe('role matrix', function (): void {
    it('guards POST ai-draft with inbox.reply', function (): void {
        [$c] = aiDraftThread();
        $this->fake->respond(...array_fill(0, 30, ['reply' => 'Hi Rafi, your order TMB-26-00126 is on the way.']));

        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->postJson(aiDraftUrl($c)));
    });

    it('refuses a guest with 401', function (): void {
        [$c] = aiDraftThread();
        $this->postJson(aiDraftUrl($c))->assertUnauthorized();
    });
});
