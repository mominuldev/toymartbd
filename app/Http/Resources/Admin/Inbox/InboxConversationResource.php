<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Inbox;

use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Services\ReplyWindow;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A row of the inbox list (Figma B · 22 left column). `needs_human` = the customer's latest
 * message still awaits a staff reply. `window` is Meta's 24-hour customer-service window:
 * `applies` false on live chat. `reply` is what staff may send now ({@see ReplyWindow}):
 * `open` (a normal reply), `human_agent` (Messenger 24 h – 7 days with the switch on — a
 * reply tagged HUMAN_AGENT), `template_only` (WhatsApp after the window — an approved
 * template via `POST …/template`), `closed` (nothing); `window_closes_at` is when that state
 * ends (or, once closed, when the last 24-hour window ended).
 *
 * @property Conversation $resource
 */
#[SchemaName('InboxConversation')]
final class InboxConversationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id' => $c->id,
            /** @var 'whatsapp'|'messenger'|'live_chat'|'web_form' */
            'channel' => $c->channel->value,
            /** @var 'open'|'resolved' */
            'status' => $c->status->value,
            /** @var array{name: string|null, phone_masked: string|null, phone: string|null} Full phone only with customers.view_pii. */
            'contact' => InboxContact::for($c, $request),
            'customer_id' => $c->customer_id,
            /** @var array{id: int, name: string}|null */
            'assignee' => $c->assignee === null ? null : ['id' => $c->assignee->id, 'name' => $c->assignee->name],
            'needs_human' => $c->needs_human,
            'unread_count' => $c->unread_count,
            /** @var string|null */
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            /** @var string|null */
            'last_message_preview' => $c->last_message_preview,
            /** @var 'in'|'out'|null */
            'last_message_direction' => $c->last_message_direction,
            /** @var array{applies: bool, open: bool, expires_at: string|null} */
            'window' => InboxContact::window($c),
            /** @var array{state: 'open'|'human_agent'|'template_only'|'closed', window_closes_at: string|null} */
            'reply' => InboxContact::reply($c, app(ReplyWindow::class)),
        ];
    }
}
