<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Providers\ApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cart\CartTestKit;

/*
 * The Contact page's "Send us a message" form (owner decision 2026-11-16): each submission
 * opens or extends the sender's `web_form` conversation in the admin Inbox, one thread per
 * phone; rule 30 phones stored as E.164; validation as RFC 9457 problems; rate limited per
 * phone and per shopper IP.
 */

const CONTACT_URL = '/api/v1/storefront/contact-messages';

beforeEach(function (): void {
    $this->token = CartTestKit::storefrontToken();
});

/** @param  array<string, mixed>  $overrides */
function contactMessage(array $overrides = [], string $ip = '203.0.113.10'): TestResponse
{
    return test()->withToken(test()->token)->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(CONTACT_URL, array_merge([
        'name' => 'নুসরাত জাহান',
        'phone' => '01712345678',
        'topic' => 'delivery',
        'order_number' => 'TMB-26-00042',
        'message' => 'আমার পার্সেল এখনও আসেনি, একটু দেখবেন?',
        'locale' => 'bn',
    ], $overrides));
}

it('needs the storefront server token', function (): void {
    $this->postJson(CONTACT_URL, [])->assertUnauthorized();
});

it('opens a web_form conversation that needs a human, with the name, E.164 phone and a readable body', function (): void {
    contactMessage()->assertStatus(202)->assertExactJson(['status' => 'received']);

    $c = Conversation::query()->sole();
    expect($c->channel)->toBe(InboxChannel::WebForm)
        ->and($c->external_thread_id)->toBe('web:+8801712345678')
        ->and($c->contact_name)->toBe('নুসরাত জাহান')
        ->and($c->contact_phone)->toBe('+8801712345678')
        ->and($c->needs_human)->toBeTrue()
        ->and($c->unread_count)->toBe(1)
        ->and($c->customer_id)->toBeNull();

    $m = ConversationMessage::query()->sole();
    expect($m->direction)->toBe(MessageDirection::In)
        ->and($m->channel)->toBe(InboxChannel::WebForm)
        ->and($m->body)->toContain('Delivery')
        ->and($m->body)->toContain('TMB-26-00042')
        ->and($m->body)->toContain('আমার পার্সেল এখনও আসেনি');
});

it('stores the order number in its canonical form and leaves it out when not given', function (): void {
    contactMessage(['order_number' => '26-00042', 'topic' => 'order'])->assertStatus(202);
    contactMessage(['order_number' => null, 'topic' => 'gifts_bulk', 'message' => 'Do you take bulk orders for a school?'])->assertStatus(202);

    [$first, $second] = ConversationMessage::query()->orderBy('id')->get()->all();
    expect($first->body)->toContain('TMB-26-00042')
        ->and($second->body)->not->toContain('Order:')
        ->and($second->body)->toContain('Gifts & bulk orders');
});

it('joins one conversation per phone, whatever format the phone was typed in', function (): void {
    contactMessage()->assertStatus(202);
    contactMessage(['phone' => '+880 1712-345678', 'message' => 'Any update on this please?'])->assertStatus(202);

    expect(Conversation::query()->count())->toBe(1)
        ->and(ConversationMessage::query()->count())->toBe(2)
        ->and(Conversation::query()->sole()->unread_count)->toBe(2);
});

it('links the customer who has that phone', function (): void {
    $customer = Customer::factory()->create(['phone' => '+8801712345678']);

    contactMessage()->assertStatus(202);

    expect(Conversation::query()->sole()->customer_id)->toBe($customer->id);
});

it('rejects a phone outside 013–019 (rule 30) without echoing it', function (string $phone): void {
    $response = contactMessage(['phone' => $phone])->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error')
        ->assertJsonPath('errors.0.field', 'phone');

    expect($response->getContent())->not->toContain($phone)
        ->and(Conversation::query()->count())->toBe(0);
})->with(['01112345678', '01012345678', '01212345678', '06012345678']);

it('validates the message, topic, name and order number', function (array $overrides, string $field): void {
    contactMessage($overrides)->assertUnprocessable()->assertJsonPath('errors.0.field', $field);

    expect(Conversation::query()->count())->toBe(0);
})->with([
    'message too short' => [['message' => 'Hi there'], 'message'],
    'message too long' => [['message' => str_repeat('a', 501)], 'message'],
    'unknown topic' => [['topic' => 'complaint'], 'topic'],
    'missing name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 81)], 'name'],
    'not an order number' => [['order_number' => 'abc'], 'order_number'],
    'bad locale' => [['locale' => 'fr'], 'locale'],
]);

it('allows 5 messages an hour per phone, then answers 429', function (): void {
    foreach (range(1, ApiServiceProvider::CONTACT_MESSAGE_PER_PHONE_PER_HOUR) as $i) {
        contactMessage([], '203.0.113.'.$i)->assertStatus(202);
    }

    contactMessage([], '203.0.113.99')->assertStatus(429)->assertHeader('Retry-After')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/too-many-requests');
    contactMessage(['phone' => '01812345678'], '203.0.113.99')->assertStatus(202);

    expect(ConversationMessage::query()->count())->toBe(6);
});

it('allows 10 messages an hour per client IP, across phones', function (): void {
    foreach (range(0, ApiServiceProvider::CONTACT_MESSAGE_PER_IP_PER_HOUR - 1) as $i) {
        contactMessage(['phone' => '0171234560'.$i])->assertStatus(202);
    }

    contactMessage(['phone' => '01912345678'])->assertStatus(429);
});
