<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Jobs\SendCartRecoveryMessage;
use App\Domain\Notification\Jobs\SendNotification;
use App\Domain\Notification\Models\CartRecovery;
use App\Domain\Notification\Models\NotificationLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Re-queue a dead-lettered message from the admin log (P4-NOT-01). Only dead letters:
 * a `failed` WhatsApp send has already fallen back to SMS, and retrying it would send the
 * customer the same update twice.
 *
 * A row is retried through the job that sends its kind of message: an abandoned-cart reminder
 * (`notifiable_type` = cart recovery, template `cart.recovery`) goes back to
 * SendCartRecoveryMessage, which re-checks — at send time — that the cart has not become an
 * order (or been merged / expired) and that marketing consent still stands, and skips the
 * reminder otherwise. Order messages go back to SendNotification. (Sending a cart-recovery row
 * through SendNotification failed it as `notifiable_missing`: there is no order behind it.)
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

        if ($log->notifiable_type === CartRecovery::NOTIFIABLE) {
            SendCartRecoveryMessage::dispatch($log->id);
        } else {
            SendNotification::dispatch($log->id);
        }

        return $log;
    }
}
