<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\WhatsAppDeliveryStatus;
use App\Domain\Notification\Models\NotificationLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a WhatsApp delivery status (from the Inbox's verified webhook, via
 * WhatsAppMessageStatusReceived) to the `notification_logs` row with that provider message id.
 * Forward only ({@see WhatsAppDeliveryStatus::canAdvance()}); a replay or an out-of-order status
 * changes nothing. An id that is no notification of ours is ignored.
 *
 * `failed` after acceptance means the customer got nothing (e.g. Meta error 131026,
 * undeliverable), so the same message is queued over SMS once — the fallback the send job
 * already uses when Meta refuses a template outright (05-content spec A.17). `status` (our send
 * attempt, what the spend screen counts) is left as `sent`.
 */
final readonly class RecordWhatsAppDeliveryStatus
{
    public function __construct(private QueueSmsFallback $fallback) {}

    public function handle(string $providerMessageId, WhatsAppDeliveryStatus $next, Carbon $at, ?string $errorCode, ?string $errorTitle): bool
    {
        $failedLog = null;

        $changed = DB::transaction(function () use ($providerMessageId, $next, $at, $errorCode, $errorTitle, &$failedLog): bool {
            $log = NotificationLog::query()
                ->where('channel', NotificationChannel::WhatsApp->value)
                ->where('provider_message_id', $providerMessageId)
                ->lockForUpdate()
                ->first();
            if ($log === null || ! WhatsAppDeliveryStatus::canAdvance($log->delivery_status, $next)) {
                return false;
            }

            $log->delivery_status = $next;
            match ($next) {
                WhatsAppDeliveryStatus::Sent => null,
                WhatsAppDeliveryStatus::Delivered => $log->delivered_at = $at,
                WhatsAppDeliveryStatus::Read => $log->read_at = $at,
                WhatsAppDeliveryStatus::Failed => $log->delivery_error = mb_substr(
                    'provider_error'.($errorCode !== null ? ':'.$errorCode : '').($errorTitle !== null ? ' '.$errorTitle : ''),
                    0,
                    300,
                ),
            };
            if ($next === WhatsAppDeliveryStatus::Read && $log->delivered_at === null) {
                $log->delivered_at = $at;
            }
            $log->save();

            if ($next === WhatsAppDeliveryStatus::Failed) {
                $failedLog = $log;
            }

            return true;
        });

        // After commit: the fallback opens its own transaction and queues its job.
        if ($failedLog instanceof NotificationLog) {
            $this->fallback->handle($failedLog);
        }

        return $changed;
    }
}
