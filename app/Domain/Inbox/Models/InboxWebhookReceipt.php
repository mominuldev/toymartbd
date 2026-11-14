<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Inbox\Enums\InboxChannel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A signature-verified inbox webhook body, kept `inbox.receipt_retention_days` (90) for
 * disputes. Never written before the signature check (rule 19). Never logged.
 *
 * @property int $id
 * @property InboxChannel $channel
 * @property string $body_sha256
 * @property string $payload
 * @property Carbon|null $processed_at
 * @property int $events_count
 * @property string|null $error
 * @property Carbon|null $created_at
 */
#[Table(name: 'inbox_webhook_receipts')]
final class InboxWebhookReceipt extends Model
{
    use MassPrunable;

    /** @var list<string> */
    protected $fillable = ['channel', 'body_sha256', 'payload', 'processed_at', 'events_count', 'error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => InboxChannel::class,
            'processed_at' => 'datetime',
            'events_count' => 'integer',
        ];
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        $days = config('inbox.receipt_retention_days', 90);

        return self::query()->where('created_at', '<', now()->subDays(is_int($days) ? $days : 90));
    }
}
