<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\SystemEvent;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Support\MessagePreview;
use App\Domain\Ordering\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Demo inbox conversations (Figma B · 22), LOCAL / TESTING ONLY. No real channel exists, so
 * these are invented threads written straight to the inbox tables — nothing is sent. The first
 * WhatsApp thread links the demo customer with the most orders, so the customer panel has
 * figures when DEMO_SEED_TRANSACTIONS is on.
 *
 * Idempotent: clears and rewrites only conversations whose thread id starts with `demo-`.
 * Called from DatabaseSeeder in local/testing only (never in production).
 */
class DemoInboxSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoInboxSeeder is for local and testing only.');
        }

        Conversation::query()->where('external_thread_id', 'like', 'demo-%')->each(function (Conversation $c): void {
            $c->messages()->delete();
            $c->delete();
        });

        $owner = User::query()->where('email', 'mominulfed@gmail.com')->first();
        $agent = User::query()->where('email', 'order_operator@toymart.test')->first() ?? $owner;
        $customerId = Order::query()->selectRaw('customer_id, COUNT(*) as n')->groupBy('customer_id')->orderByDesc('n')->value('customer_id');
        $customer = $customerId === null ? null : Customer::query()->find($customerId);
        $now = now();

        $nusrat = $this->conversation('demo-wa-1', InboxChannel::WhatsApp, $customer->name ?? 'Nusrat Jahan', $customer->phone ?? '+8801841523907', $customer?->id, [
            'needs_human' => true, 'unread_count' => 3, 'assigned_to' => $agent?->id,
        ]);
        $this->in($nusrat, 'আমার পার্সেল কোথায়? অর্ডার TMB-26-00121', $now->copy()->subMinutes(4));
        $this->system($nusrat, SystemEvent::Assigned, $owner, $agent?->name, $now->copy()->subMinutes(3));
        $this->note($nusrat, $agent, 'Customer refused at door on 06 Nov. Ask for ৳300 advance before re-sending (risk 23).', $now->copy()->subMinutes(3));
        $this->out($nusrat, $agent, 'Apa, ami Rina. Apnar order ta abar pathate pari, delivery charge ৳130 lagbe. Thik ache?', $now->copy()->subMinutes(2), DeliveryStatus::Read);
        $this->in($nusrat, 'আচ্ছা, কবে পাবো?', $now->copy()->subMinutes(2));

        $shahriar = $this->conversation('demo-fb-1', InboxChannel::Messenger, 'Shahriar Kabir', null, null, ['unread_count' => 1, 'needs_human' => true]);
        $this->in($shahriar, 'Can you deliver tonight in Bashundhara?', $now->copy()->subMinutes(12));

        $farhana = $this->conversation('demo-lc-1', InboxChannel::LiveChat, 'Farhana Yasmin', null, null, ['status' => ConversationStatus::Resolved, 'resolved_at' => $now->copy()->subMinutes(50)]);
        $this->in($farhana, 'Do you have the doll set in pink?', $now->copy()->subMinutes(70));
        $this->out($farhana, $agent, 'Yes! Sending the link now.', $now->copy()->subMinutes(66), DeliveryStatus::Delivered);
        $this->in($farhana, 'Thanks! Received the doll set 🙏', $now->copy()->subMinutes(60));
        $this->system($farhana, SystemEvent::Resolved, $agent, null, $now->copy()->subMinutes(50));

        $old = $this->conversation('demo-wa-2', InboxChannel::WhatsApp, 'Mehedi Hasan', '+8801712345678', null, []);
        $this->in($old, 'Price koto?', $now->copy()->subHours(30));
        $this->out($old, $agent, '৳1,250, free delivery inside Dhaka.', $now->copy()->subHours(29), DeliveryStatus::Failed, 'outside_window');
    }

    /** @param  array<string, mixed>  $extra */
    private function conversation(string $thread, InboxChannel $channel, string $name, ?string $phone, ?int $customerId, array $extra): Conversation
    {
        return Conversation::query()->create([
            'channel' => $channel,
            'external_thread_id' => $thread,
            'contact_name' => $name,
            'contact_phone' => $phone,
            'customer_id' => $customerId,
            'status' => ConversationStatus::Open,
            ...$extra,
        ]);
    }

    private function in(Conversation $c, string $body, Carbon $at): void
    {
        ConversationMessage::query()->forceCreate([
            'conversation_id' => $c->id, 'channel' => $c->channel, 'direction' => MessageDirection::In, 'kind' => MessageKind::Text,
            'body' => $body, 'provider_message_id' => 'demo-'.$c->id.'-'.$at->getTimestamp(), 'provider_sent_at' => $at, 'created_at' => $at,
        ]);
        $c->forceFill(['last_message_at' => $at, 'last_inbound_at' => $at, 'last_message_preview' => MessagePreview::of($body), 'last_message_direction' => 'in'])->save();
    }

    private function out(Conversation $c, ?User $by, string $body, Carbon $at, DeliveryStatus $status, ?string $failure = null): void
    {
        ConversationMessage::query()->forceCreate([
            'conversation_id' => $c->id, 'channel' => $c->channel, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Text,
            'body' => $body, 'sender_user_id' => $by?->id, 'delivery_status' => $status, 'sent_at' => $at, 'created_at' => $at,
            'read_at' => $status === DeliveryStatus::Read ? $at : null, 'failure_code' => $failure, 'failed_at' => $failure === null ? null : $at,
        ]);
        $c->forceFill(['last_message_at' => $at, 'last_message_preview' => MessagePreview::of($body), 'last_message_direction' => 'out'])->save();
    }

    private function note(Conversation $c, ?User $by, string $body, Carbon $at): void
    {
        ConversationMessage::query()->forceCreate([
            'conversation_id' => $c->id, 'channel' => $c->channel, 'direction' => MessageDirection::Out, 'kind' => MessageKind::Note,
            'body' => $body, 'sender_user_id' => $by?->id, 'created_at' => $at,
        ]);
    }

    private function system(Conversation $c, SystemEvent $event, ?User $by, ?string $assignee, Carbon $at): void
    {
        ConversationMessage::query()->forceCreate([
            'conversation_id' => $c->id, 'channel' => $c->channel, 'direction' => MessageDirection::Out, 'kind' => MessageKind::System,
            'system_event' => $event, 'sender_user_id' => $by?->id, 'meta' => ['assignee' => $assignee, 'by' => $by?->name], 'created_at' => $at,
        ]);
    }
}
