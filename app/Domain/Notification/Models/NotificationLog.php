<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempted message and what it cost (DATABASE-DESIGN §3.8, P4-NOT-01).
 * `recipient` is masked; the full address is resolved from the notifiable at send time
 * and never stored (rule 20). `payload` holds the template variables, which by design
 * never include a phone number or an address.
 *
 * @property int $id
 * @property NotificationChannel $channel
 * @property string $template
 * @property NotificationCategory $category
 * @property string $locale
 * @property string|null $recipient
 * @property string|null $recipient_hash
 * @property string|null $notifiable_type
 * @property int|null $notifiable_id
 * @property array<string, string>|null $payload
 * @property NotificationStatus $status
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property int|null $segments
 * @property string|null $encoding
 * @property Money $cost
 * @property string|null $error
 * @property int $attempts
 * @property int|null $fallback_of_id
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NotificationLog|null $fallbackOf
 */
final class NotificationLog extends Model
{
    /** `notifiable_type` for an order. Stored as a short alias, not a class name. */
    public const string NOTIFIABLE_ORDER = 'order';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cost' => '0.00',
        'attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'category' => NotificationCategory::class,
            'status' => NotificationStatus::class,
            'payload' => 'array',
            'cost' => MoneyCast::class,
            'notifiable_id' => 'integer',
            'segments' => 'integer',
            'attempts' => 'integer',
            'fallback_of_id' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<NotificationLog, $this> */
    public function fallbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_of_id');
    }

    /** @return array<string, string> */
    public function variables(): array
    {
        return array_map(strval(...), $this->payload ?? []);
    }

    public static function hashRecipient(string $address): string
    {
        return hash('sha256', mb_strtolower(trim($address)));
    }
}
