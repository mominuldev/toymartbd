<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\SystemEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a thread. Only `kind = text, direction = out` is ever handed to a channel
 * driver; notes and system lines never leave the system.
 *
 * @property int $id
 * @property int $conversation_id
 * @property InboxChannel $channel
 * @property MessageDirection $direction
 * @property MessageKind $kind
 * @property string|null $body
 * @property SystemEvent|null $system_event
 * @property array<string, mixed>|null $meta
 * @property int|null $sender_user_id
 * @property DeliveryStatus|null $delivery_status
 * @property string|null $provider_message_id
 * @property string|null $failure_code
 * @property string|null $failure_reason
 * @property int $attempts
 * @property Carbon|null $provider_sent_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation $conversation
 * @property-read User|null $sender
 */
#[Table(name: 'inbox_messages')]
final class ConversationMessage extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'conversation_id', 'channel', 'direction', 'kind', 'body', 'system_event', 'meta', 'sender_user_id',
        'delivery_status', 'provider_message_id', 'failure_code', 'failure_reason', 'attempts',
        'provider_sent_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'text', 'attempts' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'channel' => InboxChannel::class,
            'direction' => MessageDirection::class,
            'kind' => MessageKind::class,
            'system_event' => SystemEvent::class,
            'meta' => 'array',
            'sender_user_id' => 'integer',
            'delivery_status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'provider_sent_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
