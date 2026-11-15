<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\SystemEvent;
use App\Domain\Inbox\Jobs\SendInboxMessage;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Feature\Inbox\InboxFixtures;
use Tests\Support\RoleMatrix;

/*
 * Unified inbox admin API (P10, Figma B · 22): list + tabs, thread + customer panel, replies
 * queued after commit, the 24-hour window, notes that never leave, PII masking, allowlisted
 * filters (rule 16) and the 403 role matrix (rule 21).
 */

const INBOX = '/api/v1/admin/inbox';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
});

function inboxStaff(Role $role = Role::SuperAdmin): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    auth()->forgetGuards();
    test()->actingAs($user, 'web');

    return $user;
}

/** A custom role with exactly these permissions (no default role lacks view_pii but has the inbox). */
function inboxStaffWith(array $permissions): User
{
    (new RolesAndPermissionsSeeder)->run();
    $role = RoleModel::findOrCreate('inbox_only_'.count($permissions), 'web');
    $role->syncPermissions(array_map(static fn (Permission $p): string => $p->value, $permissions));
    $user = User::factory()->create()->refresh();
    $user->assignRole($role);
    test()->actingAs($user->refresh(), 'web');

    return $user;
}

describe('role matrix', function (): void {
    it('guards every inbox route', function (): void {
        Queue::fake();
        $c = InboxFixtures::conversation();
        $product = Product::factory()->create();
        $base = INBOX.'/conversations/'.$c->id;

        RoleMatrix::assert($this, Permission::InboxView, fn () => $this->getJson(INBOX.'/conversations'));
        RoleMatrix::assert($this, Permission::InboxView, fn () => $this->getJson($base));
        RoleMatrix::assert($this, Permission::InboxView, fn () => $this->getJson($base.'/messages'));
        RoleMatrix::assert($this, Permission::InboxView, fn () => $this->postJson($base.'/read'));
        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->getJson(INBOX.'/assignees'));
        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->postJson($base.'/messages', ['body' => 'Hello']), 202);
        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->postJson($base.'/notes', ['body' => 'Called her']), 201);
        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->postJson($base.'/quick-replies', ['kind' => 'product_card', 'target_id' => $product->id]), 202);
        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->postJson($base.'/assign', ['user_id' => null]));
        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->postJson($base.'/resolve'));
        RoleMatrix::assert($this, Permission::InboxReply, fn () => $this->postJson($base.'/reopen'));
    });
});

describe('list', function (): void {
    it('lists newest first with tab counts', function (): void {
        $me = inboxStaff();
        $old = InboxFixtures::conversation(['last_message_at' => now()->subHour(), 'needs_human' => false]);
        $mine = InboxFixtures::conversation(['assigned_to' => $me->id, 'last_message_at' => now()->subMinutes(5)]);
        $fb = InboxFixtures::conversation(['channel' => InboxChannel::Messenger, 'contact_phone' => null, 'last_message_at' => now()->subMinute()]);

        $response = $this->getJson(INBOX.'/conversations')->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe([$fb->id, $mine->id, $old->id])
            ->and($response->json('meta.counts'))->toBe(['all' => 3, 'mine' => 1, 'needs_human' => 2]);
        $response->assertJsonPath('data.0.channel', 'messenger')
            ->assertJsonPath('data.1.assignee.id', $me->id)
            ->assertJsonPath('data.0.window.applies', true)
            ->assertJsonPath('data.0.window.open', true);
    });

    it('filters by tab, channel, status and search', function (): void {
        $me = inboxStaff();
        $customer = Customer::factory()->create(['name' => 'Shahriar Kabir', 'phone' => '+8801712345678']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'number' => 'TMB-26-00777']);
        $sk = InboxFixtures::conversation(['contact_name' => null, 'contact_phone' => null, 'customer_id' => $customer->id, 'channel' => InboxChannel::Messenger, 'assigned_to' => $me->id]);
        $nj = InboxFixtures::conversation();
        $resolved = InboxFixtures::conversation(['contact_name' => 'Farhana Yasmin', 'contact_phone' => '+8801911000222', 'status' => ConversationStatus::Resolved, 'needs_human' => false]);

        $ids = fn (string $query): array => array_column($this->getJson(INBOX.'/conversations?'.$query)->assertOk()->json('data'), 'id');

        expect($ids('filter[view]=mine'))->toBe([$sk->id])
            ->and($ids('filter[view]=needs_human'))->toEqualCanonicalizing([$sk->id, $nj->id])
            ->and($ids('filter[channel]=messenger'))->toBe([$sk->id])
            ->and($ids('filter[status]=resolved'))->toBe([$resolved->id])
            ->and($ids('filter[search]=Shahriar'))->toBe([$sk->id])
            ->and($ids('filter[search]=farhana'))->toBe([$resolved->id])
            ->and($ids('filter[search]=01911000222'))->toBe([$resolved->id])
            ->and($ids('filter[search]=5678'))->toBe([$sk->id])
            ->and($ids('filter[search]='.$order->number))->toBe([$sk->id])
            ->and($ids('filter[search]=tmb-26-007'))->toBe([$sk->id]);
    });

    it('refuses an unlisted filter, parameter or value with 400', function (string $query): void {
        inboxStaff();

        $this->getJson(INBOX.'/conversations?'.$query)
            ->assertStatus(400)
            ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
    })->with([
        'unknown filter' => ['filter[assignee]=1'],
        'unknown parameter' => ['sort=-last_message_at'],
        'AI tab is not built' => ['filter[view]=ai'],
        'unknown channel' => ['filter[channel]=telegram'],
        'unknown status' => ['filter[status]=snoozed'],
    ]);

    it('refuses an unlisted parameter on the thread messages', function (): void {
        inboxStaff();
        $c = InboxFixtures::conversation();

        $this->getJson(INBOX.'/conversations/'.$c->id.'/messages?per_page=500')->assertStatus(400);
    });
});

describe('PII', function (): void {
    it('masks the phone without customers.view_pii and shows it with', function (): void {
        $c = InboxFixtures::conversation();

        inboxStaffWith([Permission::InboxView]);
        $this->getJson(INBOX.'/conversations')->assertOk()
            ->assertJsonPath('data.0.contact.phone_masked', '018****3907')
            ->assertJsonPath('data.0.contact.phone', null);
        $this->getJson(INBOX.'/conversations/'.$c->id)->assertOk()
            ->assertJsonPath('contact.phone', null)
            ->assertJsonPath('customer', null);
        expect($this->getJson(INBOX.'/conversations')->getContent())->not->toContain('1841523907');

        inboxStaff(Role::OrderOperator);
        $this->getJson(INBOX.'/conversations')->assertOk()->assertJsonPath('data.0.contact.phone', '+8801841523907');
    });
});

describe('thread', function (): void {
    it('shows the customer panel: orders, OUR risk score, lifetime value and the last order', function (): void {
        inboxStaff(Role::OrderOperator);
        $customer = Customer::factory()->create(['phone' => '+8801841523907']);
        $customer->forceFill(['orders_count' => 2, 'delivered_orders_count' => 1])->save();
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => now()->subDays(9)]);
        $last = Order::factory()->status(OrderStatus::Returned)->total('2280.00')->create(['customer_id' => $customer->id, 'number' => 'TMB-26-00121']);
        CustomerRiskProfile::query()->create(['phone' => $customer->phone, 'customer_id' => $customer->id, 'internal_score' => 23, 'score_computed_at' => now()]);
        $c = InboxFixtures::conversation(['customer_id' => $customer->id]);

        $this->getJson(INBOX.'/conversations/'.$c->id)->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer.orders_count', 2)
            ->assertJsonPath('customer.delivered_orders_count', 1)
            ->assertJsonPath('customer.lifetime_value', '0.00')
            ->assertJsonPath('customer.risk.score', 23)
            ->assertJsonPath('customer.risk.source', 'ours')
            ->assertJsonPath('customer.risk.level', 'low')
            ->assertJsonPath('customer.last_order.id', $last->id)
            ->assertJsonPath('customer.last_order.number', 'TMB-26-00121')
            ->assertJsonPath('customer.last_order.status', 'returned')
            ->assertJsonPath('customer.last_order.grand_total', '2280.00');
    });

    it('pages messages newest first and clears unread on read', function (): void {
        inboxStaff();
        $c = InboxFixtures::conversation(['unread_count' => 3]);
        foreach (range(1, 55) as $i) {
            ConversationMessage::query()->create(['conversation_id' => $c->id, 'channel' => InboxChannel::WhatsApp, 'direction' => MessageDirection::In, 'kind' => MessageKind::Text, 'body' => "m{$i}"]);
        }

        $first = $this->getJson(INBOX.'/conversations/'.$c->id.'/messages')->assertOk();
        expect($first->json('data.0.body'))->toBe('m55')->and(count($first->json('data')))->toBe(50);
        $older = $this->getJson(INBOX.'/conversations/'.$c->id.'/messages?cursor='.$first->json('meta.next_cursor'))->assertOk();
        expect(array_column($older->json('data'), 'body'))->toBe(['m5', 'm4', 'm3', 'm2', 'm1']);

        $this->postJson(INBOX.'/conversations/'.$c->id.'/read')->assertOk()->assertJsonPath('unread_count', 0);
    });
});

describe('replies', function (): void {
    it('records a reply as queued and queues the send after commit', function (): void {
        Queue::fake();
        $me = inboxStaff(Role::OrderOperator);
        $c = InboxFixtures::conversation();

        $this->postJson(INBOX.'/conversations/'.$c->id.'/messages', ['body' => '  Apa, ami Rina.  '])
            ->assertStatus(202)
            ->assertJsonPath('kind', 'text')
            ->assertJsonPath('direction', 'out')
            ->assertJsonPath('body', 'Apa, ami Rina.')
            ->assertJsonPath('delivery_status', 'queued')
            ->assertJsonPath('sender.id', $me->id);

        Queue::assertPushed(SendInboxMessage::class, fn (SendInboxMessage $job): bool => $job->afterCommit === true && $job->queue === 'notify');
        $c->refresh();
        expect($c->needs_human)->toBeFalse()->and($c->unread_count)->toBe(0)->and($c->last_message_direction)->toBe('out');
    });

    it('sends through the WhatsApp Cloud API outside any transaction, and never logs the number or body', function (): void {
        config(['inbox.whatsapp.driver' => 'cloud', 'inbox.whatsapp.phone_number_id' => '106540352242922', 'inbox.whatsapp.access_token' => 'EAAG-TEST-TOKEN']);
        Log::spy();
        inboxStaff();
        $c = InboxFixtures::conversation(['external_thread_id' => '8801841523907']);
        $baseline = DB::transactionLevel(); // RefreshDatabase wraps every test in one.
        $levels = [];
        Http::fake(['graph.facebook.com/*' => function (HttpRequest $request) use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '8801841523907', 'wa_id' => '8801841523907']], 'messages' => [['id' => 'wamid.SENT0001']]]);
        }]);

        $id = $this->postJson(INBOX.'/conversations/'.$c->id.'/messages', ['body' => 'Delivery charge ৳130 lagbe.'])->assertStatus(202)->json('id');

        expect($levels)->toBe([$baseline]);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v27.0/106540352242922/messages'
            && $r->hasHeader('Authorization', 'Bearer EAAG-TEST-TOKEN')
            && $r['messaging_product'] === 'whatsapp' && $r['to'] === '8801841523907' && $r['type'] === 'text'
            && $r['text']['body'] === 'Delivery charge ৳130 lagbe.');
        $message = ConversationMessage::query()->findOrFail($id);
        expect($message->delivery_status)->toBe(DeliveryStatus::Sent)->and($message->provider_message_id)->toBe('wamid.SENT0001');
        Log::shouldNotHaveReceived('info', fn (string $m, array $ctx = []): bool => str_contains(json_encode($ctx) ?: '', '1841523907') || str_contains(json_encode($ctx) ?: '', '130'));
    });

    it('maps WhatsApp error 131047 to outside_window and retries a 5xx', function (): void {
        config(['inbox.whatsapp.driver' => 'cloud', 'inbox.whatsapp.phone_number_id' => '1', 'inbox.whatsapp.access_token' => 't']);
        $c = InboxFixtures::conversation();
        $queued = fn (): ConversationMessage => ConversationMessage::query()->create(['conversation_id' => $c->id, 'channel' => InboxChannel::WhatsApp, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Text, 'body' => 'x', 'delivery_status' => DeliveryStatus::Queued]);

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)
            ->push(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 503)]);

        $first = $queued();
        app()->call([new SendInboxMessage($first->id), 'handle']);
        expect($first->refresh()->delivery_status)->toBe(DeliveryStatus::Failed)->and($first->failure_code)->toBe('outside_window');

        $second = $queued();
        expect(fn () => app()->call([new SendInboxMessage($second->id), 'handle']))->toThrow(RuntimeException::class);
        expect($second->refresh()->delivery_status)->toBe(DeliveryStatus::Queued);
    });

    it('refuses a reply outside the 24-hour window and queues nothing', function (): void {
        Queue::fake();
        inboxStaff();
        $c = InboxFixtures::conversation(['last_inbound_at' => now()->subHours(25)]);

        $this->getJson(INBOX.'/conversations/'.$c->id)->assertOk()->assertJsonPath('window.open', false);
        $this->postJson(INBOX.'/conversations/'.$c->id.'/messages', ['body' => 'Hello?'])
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');

        Queue::assertNothingPushed();
        expect(ConversationMessage::query()->count())->toBe(0);
    });

    it('has no window on live chat', function (): void {
        Queue::fake();
        inboxStaff();
        $c = InboxFixtures::conversation(['channel' => InboxChannel::LiveChat, 'last_inbound_at' => now()->subDays(3)]);

        $this->postJson(INBOX.'/conversations/'.$c->id.'/messages', ['body' => 'Hi'])->assertStatus(202);
        $this->getJson(INBOX.'/conversations/'.$c->id)->assertJsonPath('window.applies', false)->assertJsonPath('window.open', true);
    });

    it('fails a queued reply whose window closed before the job ran, without calling the provider', function (): void {
        config(['inbox.whatsapp.driver' => 'cloud', 'inbox.whatsapp.phone_number_id' => '1', 'inbox.whatsapp.access_token' => 't']);
        Http::fake();
        $c = InboxFixtures::conversation(['last_inbound_at' => now()->subHours(24)->subMinute()]);
        $m = ConversationMessage::query()->create(['conversation_id' => $c->id, 'channel' => InboxChannel::WhatsApp, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Text, 'body' => 'late', 'delivery_status' => DeliveryStatus::Queued]);

        SendInboxMessage::dispatchSync($m->id);

        Http::assertNothingSent();
        expect($m->refresh()->delivery_status)->toBe(DeliveryStatus::Failed)->and($m->failure_code)->toBe('outside_window');
    });

    it('validates the reply body', function (): void {
        inboxStaff();
        $c = InboxFixtures::conversation();

        $this->postJson(INBOX.'/conversations/'.$c->id.'/messages', ['body' => ''])->assertStatus(422);
        $this->postJson(INBOX.'/conversations/'.$c->id.'/messages', ['body' => str_repeat('a', 4097)])->assertStatus(422);
    });
});

describe('internal notes', function (): void {
    it('stores a note that is never queued, never sent and never becomes the preview', function (): void {
        Queue::fake();
        inboxStaff();
        $c = InboxFixtures::conversation();

        $this->postJson(INBOX.'/conversations/'.$c->id.'/notes', ['body' => 'Customer refused at door; ask ৳300 advance.'])
            ->assertStatus(201)
            ->assertJsonPath('kind', 'note')
            ->assertJsonPath('delivery_status', null);

        Queue::assertNothingPushed();
        expect($c->refresh()->last_message_preview)->toBe('আমার পার্সেল কোথায়?');
    });

    it('cannot be sent even if its id reaches the send job', function (): void {
        config(['inbox.whatsapp.driver' => 'cloud', 'inbox.whatsapp.phone_number_id' => '1', 'inbox.whatsapp.access_token' => 't']);
        Http::fake();
        $c = InboxFixtures::conversation();
        $note = ConversationMessage::query()->create(['conversation_id' => $c->id, 'channel' => InboxChannel::WhatsApp, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Note, 'body' => 'internal', 'delivery_status' => DeliveryStatus::Queued]);

        SendInboxMessage::dispatchSync($note->id);

        Http::assertNothingSent();
        expect($note->refresh()->delivery_status)->toBe(DeliveryStatus::Queued);
    });

    it('is allowed outside the window', function (): void {
        inboxStaff();
        $c = InboxFixtures::conversation(['last_inbound_at' => now()->subDays(5)]);

        $this->postJson(INBOX.'/conversations/'.$c->id.'/notes', ['body' => 'Follow up by phone'])->assertStatus(201);
    });
});

describe('assignment and status', function (): void {
    it('assigns to me, to someone else, and unassigns, with a system line each time', function (): void {
        $me = inboxStaff();
        $rina = User::factory()->withRole(Role::OrderOperator)->create(['name' => 'Rina Akter']);
        $c = InboxFixtures::conversation();

        $this->postJson(INBOX.'/conversations/'.$c->id.'/assign', ['user_id' => $me->id])->assertOk()->assertJsonPath('assignee.id', $me->id);
        $this->postJson(INBOX.'/conversations/'.$c->id.'/assign', ['user_id' => $rina->id])->assertOk()->assertJsonPath('assignee.name', 'Rina Akter');
        $this->postJson(INBOX.'/conversations/'.$c->id.'/assign', ['user_id' => null])->assertOk()->assertJsonPath('assignee', null);

        $events = ConversationMessage::query()->where('kind', MessageKind::System->value)->orderBy('id')->get();
        expect($events->map(fn ($m) => $m->system_event)->all())->toBe([SystemEvent::Assigned, SystemEvent::Assigned, SystemEvent::Unassigned])
            ->and($events[1]->meta)->toBe(['assignee' => 'Rina Akter', 'by' => $me->name]);

        $this->getJson(INBOX.'/conversations/'.$c->id.'/messages')->assertJsonPath('data.1.assignee_name', 'Rina Akter');
    });

    it('refuses to assign to someone who cannot reply, or who is inactive', function (): void {
        inboxStaff();
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $gone = User::factory()->withRole(Role::OrderOperator)->create(['is_active' => false]);
        $c = InboxFixtures::conversation();

        $this->postJson(INBOX.'/conversations/'.$c->id.'/assign', ['user_id' => $accountant->id])->assertStatus(422);
        $this->postJson(INBOX.'/conversations/'.$c->id.'/assign', ['user_id' => $gone->id])->assertStatus(422);
        $this->postJson(INBOX.'/conversations/'.$c->id.'/assign', [])->assertStatus(422);
    });

    it('lists assignees who can reply', function (): void {
        $me = inboxStaff();
        $rina = User::factory()->withRole(Role::OrderOperator)->create(['name' => 'Rina Akter']);
        User::factory()->withRole(Role::Accountant)->create(['name' => 'Kamrul']);

        $names = array_column($this->getJson(INBOX.'/assignees')->assertOk()->json('data'), 'name');

        expect($names)->toContain($me->name, 'Rina Akter')->not->toContain('Kamrul');
    });

    it('resolves and reopens', function (): void {
        inboxStaff();
        $c = InboxFixtures::conversation();

        $this->postJson(INBOX.'/conversations/'.$c->id.'/resolve')->assertOk()->assertJsonPath('status', 'resolved')->assertJsonPath('needs_human', false);
        $this->postJson(INBOX.'/conversations/'.$c->id.'/resolve')->assertOk();
        $this->postJson(INBOX.'/conversations/'.$c->id.'/reopen')->assertOk()->assertJsonPath('status', 'open');

        expect(ConversationMessage::query()->where('kind', 'system')->pluck('system_event')->map->value->all())->toBe(['resolved', 'reopened']);
    });
});

describe('quick replies', function (): void {
    it('sends a product card in the customer language, with no price', function (): void {
        Queue::fake();
        inboxStaff();
        config(['inbox.storefront_url' => 'https://toymartbd.com']);
        $product = Product::factory()->create(['name' => 'Baby Activity Gym Mat', 'name_bn' => 'বেবি অ্যাক্টিভিটি জিম ম্যাট', 'slug' => 'baby-activity-gym-mat']);
        $c = InboxFixtures::conversation();

        $body = $this->postJson(INBOX.'/conversations/'.$c->id.'/quick-replies', ['kind' => 'product_card', 'target_id' => $product->id])
            ->assertStatus(202)->json('body');

        expect($body)->toBe("বেবি অ্যাক্টিভিটি জিম ম্যাট\nhttps://toymartbd.com/bn/products/baby-activity-gym-mat")->not->toContain('৳');
        Queue::assertPushed(SendInboxMessage::class);
    });

    it('refuses a draft product', function (): void {
        inboxStaff();
        $product = Product::factory()->draft()->create();
        $c = InboxFixtures::conversation();

        $this->postJson(INBOX.'/conversations/'.$c->id.'/quick-replies', ['kind' => 'product_card', 'target_id' => $product->id])->assertStatus(409);
    });

    it("shares a tracking link only for the customer's own order", function (): void {
        Queue::fake();
        inboxStaff();
        $customer = Customer::factory()->create(['preferred_language' => 'en']);
        $own = Order::factory()->create(['customer_id' => $customer->id, 'number' => 'TMB-26-00121']);
        $other = Order::factory()->create();
        $c = InboxFixtures::conversation(['customer_id' => $customer->id]);

        $this->postJson(INBOX.'/conversations/'.$c->id.'/quick-replies', ['kind' => 'tracking_link', 'target_id' => $own->id])
            ->assertStatus(202)
            ->assertJsonPath('body', 'Track your order TMB-26-00121 here: '.config('notifications.short_link_base', 'tmbd.co').'/TMB-26-00121');
        $this->postJson(INBOX.'/conversations/'.$c->id.'/quick-replies', ['kind' => 'tracking_link', 'target_id' => $other->id])->assertStatus(409);
        $this->postJson(INBOX.'/conversations/'.$c->id.'/quick-replies', ['kind' => 'bkash_link', 'target_id' => 1])->assertStatus(422);
    });
});
