<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Jobs\SendNotification;
use App\Domain\Notification\Models\NotificationLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Re-queue a dead-lettered message from the admin log (P4-NOT-01). Only dead letters:
 * a `failed` WhatsApp send has already fallen back to SMS, and retrying it would send the
 * customer the same update twice.
 */
final class RetryNotification
{
    public function handle(NotificationLog $log): NotificationLog
    {
        $log = DB::transaction(static function () use ($log): NotificationLog {
            $log = NotificationLog::query()->lockForUpdate()->findOrFail($log->id);

            if ($log->status !== NotificationStatus::DeadLettered) {
                throw new InvalidArgumentException('Only a dead-lettered message can be retried.');
            }

            $log->forceFill(['status' => NotificationStatus::Queued, 'error' => null, 'failed_at' => null, 'queued_at' => now()])->save();

            return $log;
        });

        SendNotification::dispatch($log->id);

        return $log;
    }
}
