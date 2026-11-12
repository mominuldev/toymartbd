<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Jobs\SendNotification;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Services\NotificationPreferences;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * A WhatsApp send failed: queue the same message over SMS, once (05-content spec A.17:
 * "SMS for order updates exists only as a fallback when … the template send fails").
 * Respects the customer's SMS preference; never chains a second fallback.
 */
final readonly class QueueSmsFallback
{
    public function __construct(private NotificationPreferences $preferences) {}

    public function handle(NotificationLog $failed): ?NotificationLog
    {
        if ($failed->channel !== NotificationChannel::WhatsApp || ! QueueOrderNotification::offered($failed->template, NotificationChannel::Sms)) {
            return null;
        }

        $customerId = $failed->notifiable_type === NotificationLog::NOTIFIABLE_ORDER
            ? Order::query()->whereKey($failed->notifiable_id)->value('customer_id')
            : null;

        $fallback = DB::transaction(function () use ($failed, $customerId): ?NotificationLog {
            if (NotificationLog::query()->where('fallback_of_id', $failed->id)->exists()) {
                return null;
            }

            $allowed = $this->preferences->allows(is_int($customerId) ? $customerId : null, NotificationChannel::Sms, $failed->category);

            return NotificationLog::query()->create([
                'channel' => NotificationChannel::Sms,
                'template' => $failed->template,
                'category' => $failed->category,
                'locale' => $failed->locale,
                'recipient' => $failed->recipient,
                'recipient_hash' => $failed->recipient_hash,
                'notifiable_type' => $failed->notifiable_type,
                'notifiable_id' => $failed->notifiable_id,
                'payload' => $failed->payload,
                'status' => $allowed ? NotificationStatus::Queued : NotificationStatus::Skipped,
                'error' => $allowed ? null : 'opted_out',
                'fallback_of_id' => $failed->id,
                'queued_at' => now(),
            ]);
        });

        if ($fallback?->status === NotificationStatus::Queued) {
            SendNotification::dispatch($fallback->id);
        }

        return $fallback;
    }
}
