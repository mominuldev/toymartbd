<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Something about one inbox conversation changed: a message arrived or was sent, a delivery
 * status moved, the thread was read, assigned, resolved or reopened. It is pushed live to the
 * admin over the private `inbox` channel (Laravel Reverb), so the open Inbox and the nav badge
 * refresh at once instead of on their polling timer.
 *
 * The payload is a SIGNAL, not data: the conversation id only. The admin refetches through the
 * normal API, which applies the viewer's own permissions (phone masking without
 * customers.view_pii) — so nothing personal is ever broadcast (rule 20), and server data stays in
 * TanStack Query (rule 25a).
 *
 * Raised by the Conversation and ConversationMessage model hooks, so no write path can miss it,
 * and only after the surrounding transaction commits (rule 4: the push to Reverb is an HTTP call,
 * and it runs on the queue).
 */
final readonly class InboxConversationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    /** The private channel; authorised in routes/channels.php by ConversationPolicy::viewAny (inbox.view). */
    public const string CHANNEL = 'inbox';

    public function __construct(public int $conversationId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.changed';
    }

    /** @return array{conversation_id: int} */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }

    /**
     * Nothing is queued while broadcasting is off — BROADCAST_CONNECTION=null, as in the test suite
     * (env() reads the string "null" as PHP null). The admin then just polls.
     */
    public function broadcastWhen(): bool
    {
        return ! in_array(config('broadcasting.default'), [null, '', 'null'], true);
    }

    /** Customer-facing, short and cheap — the same lane as order updates. */
    public function broadcastQueue(): string
    {
        return 'notify';
    }
}
