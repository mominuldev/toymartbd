<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Cart\Models\Cart;
use App\Domain\Notification\CartRecovery\RecoveryConsent;
use App\Domain\Notification\Enums\CartRecoverySkip;
use App\Domain\Notification\Enums\CartRecoveryStatus;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Exceptions\NotificationSendFailed;
use App\Domain\Notification\Models\CartRecovery;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\SmsDrivers;
use App\Domain\Notification\Support\SmsSegments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one abandoned-cart reminder by SMS (P7). On the `notify` queue, after commit
 * (rule 4). The payload is the log id only; the phone is re-read from the cart (or its
 * customer) at send time and never stored in the log (rule 20).
 *
 * Just before sending it re-checks — the reminder stops when:
 *  - the cart became an order (or was merged/expired) → `skipped` / `converted`;
 *  - consent was withdrawn or an opt-out recorded since detection → `skipped`.
 *
 * The restore token is minted here, stored hashed on the recovery row, and exists in
 * plain text only inside the SMS body. Segments, encoding and cost go on the log row.
 */
final class SendCartRecoveryMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $logId)
    {
        $this->afterCommit();
        $queue = config('notifications.queue', 'notify');
        $this->onQueue(is_string($queue) ? $queue : 'notify');
        $tries = config('notifications.tries', 3);
        $this->tries = is_int($tries) ? $tries : 3;
        $backoff = config('notifications.backoff', [30, 120]);
        $this->backoff = is_array($backoff) ? array_values(array_map(intval(...), $backoff)) : [30, 120];
    }

    public function handle(SmsDrivers $sms, RecoveryConsent $consent): void
    {
        $log = NotificationLog::query()->find($this->logId);
        if ($log === null || $log->status !== NotificationStatus::Queued || $log->notifiable_type !== CartRecovery::NOTIFIABLE) {
            return;
        }
        $log->attempts++;
        $log->save();

        $recovery = CartRecovery::query()->find($log->notifiable_id);
        $cart = $recovery === null ? null : Cart::query()->find($recovery->cart_id);
        if ($recovery === null || $cart === null) {
            $this->finish($log, NotificationStatus::Failed, 'notifiable_missing');

            return;
        }

        if ($cart->converted_at !== null || $cart->merged_into_cart_id !== null || ! $cart->expires_at->isFuture()) {
            $this->skip($log, $recovery, CartRecoverySkip::Converted);

            return;
        }
        $check = $consent->check($cart);
        if ($check['skip'] !== null || $check['phone'] === null) {
            $this->skip($log, $recovery, $check['skip'] ?? CartRecoverySkip::NoPhone);

            return;
        }

        $template = NotificationTemplate::for($log->template, $log->channel);
        if ($template === null || ! $template->is_active) {
            $this->skip($log, $recovery, CartRecoverySkip::TemplateInactive);

            return;
        }

        $token = Str::random(24);
        $recovery->restore_token_hash = CartRecovery::hashToken($token);
        $recovery->restore_expires_at = now()->addDays(max(1, (int) config('cart_recovery.restore_ttl_days', 7)));
        $recovery->save();

        $body = $template->render($log->locale, $log->variables() + [
            'restore_url' => self::restoreUrl($log->locale, $token),
            'store_url' => (string) config('notifications.store_url', 'toymartbd.com'),
        ]);
        $result = $sms->driver()->send($check['phone']->e164(), $body, $log->template);

        $log->fill([
            'provider' => $sms->name(),
            'segments' => $result->segments > 0 ? $result->segments : SmsSegments::count($body),
            'encoding' => SmsSegments::encoding($body),
        ]);
        if (! $result->accepted) {
            $log->error = mb_substr($result->error ?? 'rejected', 0, 500);
            $log->save();

            throw new NotificationSendFailed("SMS rejected for notification {$log->id}: ".($result->error ?? 'rejected'));
        }

        $log->cost = $result->cost;
        $log->provider_message_id = $result->providerMessageId;
        $this->finish($log, NotificationStatus::Sent);
    }

    public function failed(?Throwable $exception): void
    {
        $log = NotificationLog::query()->find($this->logId);
        if ($log !== null && $log->status === NotificationStatus::Queued) {
            $this->finish($log, NotificationStatus::DeadLettered, mb_substr($exception?->getMessage() ?? 'failed', 0, 500));
        }
    }

    /** `{storefront}/cart/restore/{token}` — Bangla under `/bn` (rule 35). No PII, not the cart token. */
    public static function restoreUrl(string $locale, string $token): string
    {
        $base = rtrim((string) config('cart_recovery.storefront_url', 'https://toymartbd.com'), '/');

        return $base.($locale === 'bn' ? '/bn' : '').'/cart/restore/'.$token;
    }

    private function skip(NotificationLog $log, CartRecovery $recovery, CartRecoverySkip $reason): void
    {
        $recovery->status = CartRecoveryStatus::Skipped;
        $recovery->skip_reason = $reason;
        $recovery->save();
        $this->finish($log, NotificationStatus::Skipped, $reason->value);
    }

    private function finish(NotificationLog $log, NotificationStatus $status, ?string $error = null): void
    {
        $log->status = $status;
        $log->error = $error;
        if ($status === NotificationStatus::Sent) {
            $log->sent_at = now();
        } else {
            $log->failed_at = now();
        }
        $log->save();
    }
}
