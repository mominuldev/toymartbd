<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\AutomationKind;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\AutomationSend;
use App\Domain\Notification\Models\NotificationLog;
use Illuminate\Support\Facades\DB;

/**
 * Data minimisation after a child is removed (P8 batch 3): every birthday-reminder log row for
 * that child gets the unnamed wording in place of the name ("your child's"), and one still
 * queued is marked failed (`child_removed`) so it is never sent. The `automation_sends` rows
 * stay — they hold ids only, and they are what proves the once-a-year rule.
 */
final readonly class ForgetChildInMessages
{
    public function handle(int $childId): int
    {
        return DB::transaction(static function () use ($childId): int {
            $logIds = AutomationSend::query()
                ->where('automation', AutomationKind::Birthday->value)
                ->where('subject_id', $childId)
                ->whereNotNull('notification_log_id')
                ->pluck('notification_log_id');
            // An SMS fallback copies its WhatsApp row's payload: forget those too.
            $logs = NotificationLog::query()
                ->where(static fn ($q) => $q->whereIn('id', $logIds)->orWhereIn('fallback_of_id', $logIds))
                ->lockForUpdate()
                ->get();

            foreach ($logs as $log) {
                $payload = $log->payload ?? [];
                $payload['child_possessive'] = SendBirthdayMessages::possessive(null, $log->locale);
                $log->payload = $payload;
                if ($log->status === NotificationStatus::Queued) {
                    $log->status = NotificationStatus::Failed;
                    $log->error = 'child_removed';
                    $log->failed_at = now();
                }
                $log->save();
            }

            return $logs->count();
        });
    }
}
