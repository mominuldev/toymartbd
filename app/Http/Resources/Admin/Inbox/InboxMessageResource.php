<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Inbox;

use App\Domain\Inbox\Models\ConversationMessage;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One line of a thread. `kind`: text (a real message), note (internal — never sent) or
 * system (`system_event` with `meta.assignee`/`meta.by` names; the admin translates it).
 * `delivery_status` only on our outbound text. `unsupported_type` names a non-text inbound
 * message (image, audio…) the inbox cannot show yet.
 *
 * @property ConversationMessage $resource
 */
#[SchemaName('InboxMessage')]
final class InboxMessageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $m = $this->resource;
        $meta = $m->meta ?? [];
        $at = $m->provider_sent_at ?? $m->created_at;

        return [
            'id' => $m->id,
            /** @var 'in'|'out' */
            'direction' => $m->direction->value,
            /** @var 'text'|'note'|'system' */
            'kind' => $m->kind->value,
            /** @var string|null */
            'body' => $m->body,
            /** @var 'assigned'|'unassigned'|'resolved'|'reopened'|null */
            'system_event' => $m->system_event?->value,
            /** @var string|null Who was assigned (system line). */
            'assignee_name' => is_string($meta['assignee'] ?? null) ? $meta['assignee'] : null,
            /** @var string|null Who did it: a staff name, or `customer` for an automatic reopen. */
            'actor' => is_string($meta['by'] ?? null) ? $meta['by'] : null,
            /** @var string|null */
            'unsupported_type' => is_string($meta['type'] ?? null) ? $meta['type'] : null,
            /** @var array{id: int, name: string}|null */
            'sender' => $m->sender === null ? null : ['id' => $m->sender->id, 'name' => $m->sender->name],
            /** @var 'queued'|'sent'|'delivered'|'read'|'failed'|null */
            'delivery_status' => $m->delivery_status?->value,
            /** @var string|null `outside_window`, `not_configured`, `transport`, `provider_error:{code}` */
            'failure_code' => $m->failure_code,
            /** @var string|null */
            'failure_reason' => $m->failure_reason,
            /** @var string|null When it was written (inbound: the provider's timestamp). */
            'at' => $at?->toIso8601String(),
            /** @var string|null */
            'read_at' => $m->read_at?->toIso8601String(),
        ];
    }
}
