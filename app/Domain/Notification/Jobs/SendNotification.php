<?php

declare(strict_types=1);

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\Actions\QueueSmsFallback;
use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Exceptions\NotificationSendFailed;
use App\Domain\Notification\Mail\OrderNotificationMail;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\SmsDrivers;
use App\Domain\Notification\Support\SmsSegments;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Queries\OrderRiderContact;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one logged message (P4-NOT-01/02). On the `notify` queue, after commit (rule 4).
 *
 * The payload is only the log id: the phone number and email are re-read from the order
 * at send time, and the delivery rider's phone from the shipment, so none ever sits on the
 * queue or in the log in full (rule 20).
 *
 *  - SMS rejected or a thrown error → retried; after the last of 3 tries the row is
 *    `dead_lettered` and shows in the admin log with a Retry action.
 *  - WhatsApp rejected → `failed`, and the same message is queued over SMS once.
 *  - Inactive or missing template → `failed`, not retried (retrying cannot fix it).
 */
final class SendNotification implements ShouldQueue
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

    public function handle(SmsDrivers $sms, WhatsAppGateway $whatsApp, QueueSmsFallback $fallback, OrderRiderContact $riders): void
    {
        $log = NotificationLog::query()->find($this->logId);
        if ($log === null || $log->status !== NotificationStatus::Queued) {
            return;
        }

        $log->attempts++;
        $log->save();

        $template = NotificationTemplate::for($log->template, $log->channel);
        if ($template === null || ! $template->is_active) {
            $this->finish($log, NotificationStatus::Failed, error: 'template_inactive');

            return;
        }

        $order = $log->notifiable_type === NotificationLog::NOTIFIABLE_ORDER
            ? Order::query()->with(['customer', 'items'])->find($log->notifiable_id)
            : null;
        if ($order === null) {
            $this->finish($log, NotificationStatus::Failed, error: 'notifiable_missing');

            return;
        }

        $variables = $log->variables();
        // The payload keeps the rider's phone masked (rule 20); re-read the real number now,
        // as the recipient's is. Empty if the rider was cleared since it was queued.
        if (array_key_exists('rider_phone', $variables)) {
            $variables['rider_phone'] = $riders->phoneFor($order->id)?->national() ?? '';
        }

        match ($log->channel) {
            NotificationChannel::Sms => $this->sendSms($log, $template, $order, $variables, $sms),
            NotificationChannel::WhatsApp => $this->sendWhatsApp($log, $template, $order, $variables, $whatsApp, $fallback),
            NotificationChannel::Email => $this->sendEmail($log, $template, $order, $variables),
        };
    }

    /** After the last try: dead-letter visibly; a dead WhatsApp send still falls back to SMS. */
    public function failed(?Throwable $exception): void
    {
        $log = NotificationLog::query()->find($this->logId);
        if ($log === null || ! in_array($log->status, [NotificationStatus::Queued], true)) {
            return;
        }

        $this->finish($log, NotificationStatus::DeadLettered, error: mb_substr($exception?->getMessage() ?? 'failed', 0, 500));

        if ($log->channel === NotificationChannel::WhatsApp) {
            app(QueueSmsFallback::class)->handle($log);
        }
    }

    /** @param  array<string, string>  $variables */
    private function sendSms(NotificationLog $log, NotificationTemplate $template, Order $order, array $variables, SmsDrivers $sms): void
    {
        $phone = PhoneNumber::tryParse($order->shipping_phone);
        if ($phone === null) {
            $this->finish($log, NotificationStatus::Failed, error: 'invalid_recipient');

            return;
        }

        $body = $template->render($log->locale, $variables);
        $result = $sms->driver()->send($phone->e164(), $body, $log->template);

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

        $this->finish($log, NotificationStatus::Sent, cost: $result->cost, providerMessageId: $result->providerMessageId);
    }

    /** @param  array<string, string>  $variables */
    private function sendWhatsApp(NotificationLog $log, NotificationTemplate $template, Order $order, array $variables, WhatsAppGateway $whatsApp, QueueSmsFallback $fallback): void
    {
        $phone = PhoneNumber::tryParse($order->shipping_phone);
        if ($phone === null) {
            $this->finish($log, NotificationStatus::Failed, error: 'invalid_recipient');

            return;
        }

        $parameters = array_map(
            static fn (string $name): string => $variables[$name] ?? '',
            TemplateCatalog::whatsAppParameters($log->template),
        );
        $language = $log->locale === 'en' ? 'en' : 'bn_BD';

        $result = $whatsApp->sendTemplate($phone->e164(), (string) $template->provider_template, $language, $parameters, $log->template);
        $log->provider = (string) config('notifications.whatsapp.driver', 'log');

        if (! $result->accepted) {
            $this->finish($log, NotificationStatus::Failed, error: mb_substr($result->error ?? 'rejected', 0, 500));
            $fallback->handle($log);

            return;
        }

        $this->finish($log, NotificationStatus::Sent, cost: $result->cost, providerMessageId: $result->providerMessageId);
    }

    /** @param  array<string, string>  $variables */
    private function sendEmail(NotificationLog $log, NotificationTemplate $template, Order $order, array $variables): void
    {
        $email = $order->customer->email;
        if (! is_string($email) || $email === '') {
            $this->finish($log, NotificationStatus::Failed, error: 'invalid_recipient');

            return;
        }

        Mail::to($email)->send(OrderNotificationMail::for($template, $order, $log->locale, $variables));

        $log->provider = (string) config('mail.default', 'smtp');
        $rate = config('notifications.email.cost_per_message', '0.00');
        $this->finish($log, NotificationStatus::Sent, cost: Money::of(is_string($rate) || is_int($rate) ? $rate : '0.00'));
    }

    private function finish(NotificationLog $log, NotificationStatus $status, ?string $error = null, ?Money $cost = null, ?string $providerMessageId = null): void
    {
        $log->status = $status;
        $log->error = $error;
        if ($cost !== null) {
            $log->cost = $cost;
        }
        if ($providerMessageId !== null) {
            $log->provider_message_id = $providerMessageId;
        }
        if ($status === NotificationStatus::Sent) {
            $log->sent_at = now();
        } else {
            $log->failed_at = now();
        }
        $log->save();
    }
}
