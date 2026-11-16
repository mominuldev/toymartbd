<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One customer thread on one channel (Figma B · 22). Written only by the Inbox Actions.
 * `contact_phone` (E.164) is personal data: masked unless the viewer holds
 * customers.view_pii, never logged in full (rule 20).
 *
 * @property int $id
 * @property InboxChannel $channel
 * @property string $external_thread_id
 * @property int|null $customer_id
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property ConversationStatus $status
 * @property int|null $assigned_to
 * @property bool $needs_human
 * @property int $unread_count
 * @property Carbon|null $last_message_at
 * @property string|null $last_message_preview
 * @property string|null $last_message_direction
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read User|null $assignee
 */
#[Table(name: 'inbox_conversations')]
final class Conversation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'channel', 'external_thread_id', 'customer_id', 'contact_name', 'contact_phone', 'status',
        'assigned_to', 'needs_human', 'unread_count', 'last_message_at', 'last_message_preview',
        'last_message_direction', 'last_inbound_at', 'resolved_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'needs_human' => false, 'unread_count' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => InboxChannel::class,
            'status' => ConversationStatus::class,
            'customer_id' => 'integer',
            'assigned_to' => 'integer',
            'needs_human' => 'boolean',
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<ConversationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    /** The language we write to the customer in: their preferred language, Bangla when unknown. */
    public function locale(): string
    {
        return $this->customer?->preferred_language === 'en' ? 'en' : 'bn';
    }

    /** When the customer-service window closes; null when the channel has none or it never opened. */
    public function windowExpiresAt(): ?Carbon
    {
        if (! $this->channel->hasServiceWindow() || $this->last_inbound_at === null) {
            return null;
        }

        $hours = config('inbox.service_window_hours', 24);

        return $this->last_inbound_at->copy()->addHours(is_int($hours) ? $hours : 24);
    }

    /**
     * May a free-form reply be sent now? Always on live chat; within the window on Meta channels;
     * never on the web form (no sending channel).
     */
    public function windowOpen(?Carbon $now = null): bool
    {
        if (! $this->channel->canSend()) {
            return false;
        }
        if (! $this->channel->hasServiceWindow()) {
            return true;
        }
        $expires = $this->windowExpiresAt();

        return $expires !== null && $expires->isAfter($now ?? now());
    }
}
