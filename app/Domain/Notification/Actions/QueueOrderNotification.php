<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Jobs\SendNotification;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\NotificationPreferences;
use App\Domain\Notification\Services\OrderMessageData;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Domain\Ordering\Models\Order;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Decides who gets which message about an order, writes one `notification_logs` row per
 * message and queues the sends after commit (rules 4, 39):
 *
 *  - **Messaging**: WhatsApp utility first; SMS only when WhatsApp is disabled, not
 *    offered for this template, or the customer turned WhatsApp off. (A WhatsApp send that
 *    fails falls back to SMS in the job.) If the customer has opted out of every
 *    messaging channel, a `skipped` row records that the opt-out was respected.
 *  - **Email**: only when the customer has an address; skipped if they opted out.
 *
 * The recipient is stored masked plus hashed; the job re-reads the order for the number.
 */
final readonly class QueueOrderNotification
{
    public function __construct(
        private NotificationPreferences $preferences,
        private OrderMessageData $data,
    ) {}

    /** @return list<NotificationLog> */
    public function handle(int $orderId, string $key): array
    {
        $definition = TemplateCatalog::find($key);
        $order = Order::query()->with(['customer', 'items'])->find($orderId);

        if ($definition === null || $order === null) {
            return [];
        }

        $category = $definition['category'];
        $customer = $order->customer;
        $locale = in_array($customer->preferred_language, ['en', 'bn'], true) ? $customer->preferred_language : 'bn';
        $variables = $this->data->variables($order, $locale);
        $phone = PhoneNumber::tryParse($order->shipping_phone);

        $logs = DB::transaction(function () use ($key, $category, $order, $customer, $locale, $variables, $phone): array {
            $logs = [];
            $base = [
                'template' => $key,
                'category' => $category,
                'locale' => $locale,
                'notifiable_type' => NotificationLog::NOTIFIABLE_ORDER,
                'notifiable_id' => $order->id,
                'payload' => $variables,
                'queued_at' => now(),
            ];

            $messaging = array_values(array_filter(
                self::messagingChannels(),
                static fn (NotificationChannel $c): bool => self::offered($key, $c),
            ));

            if ($messaging !== [] && $phone !== null) {
                $chosen = null;
                foreach ($messaging as $channel) {
                    if ($this->preferences->allows($customer->id, $channel, $category)) {
                        $chosen = $channel;
                        break;
                    }
                }

                $logs[] = NotificationLog::query()->create($base + [
                    'channel' => $chosen ?? $messaging[0],
                    'recipient' => $phone->masked(),
                    'recipient_hash' => NotificationLog::hashRecipient($phone->e164()),
                    'status' => $chosen === null ? NotificationStatus::Skipped : NotificationStatus::Queued,
                    'error' => $chosen === null ? 'opted_out' : null,
                ]);
            }

            $email = $customer->email;
            if (is_string($email) && $email !== '' && self::offered($key, NotificationChannel::Email)) {
                $allowed = $this->preferences->allows($customer->id, NotificationChannel::Email, $category);
                $logs[] = NotificationLog::query()->create($base + [
                    'channel' => NotificationChannel::Email,
                    'recipient' => self::maskEmail($email),
                    'recipient_hash' => NotificationLog::hashRecipient($email),
                    'status' => $allowed ? NotificationStatus::Queued : NotificationStatus::Skipped,
                    'error' => $allowed ? null : 'opted_out',
                ]);
            }

            return $logs;
        });

        foreach ($logs as $log) {
            if ($log->status === NotificationStatus::Queued) {
                SendNotification::dispatch($log->id);
            }
        }

        return $logs;
    }

    /** @return list<NotificationChannel> WhatsApp first (rule 39), SMS as fallback. */
    public static function messagingChannels(): array
    {
        return config('notifications.whatsapp.enabled') === true
            ? [NotificationChannel::WhatsApp, NotificationChannel::Sms]
            : [NotificationChannel::Sms];
    }

    public static function offered(string $key, NotificationChannel $channel): bool
    {
        if ($channel === NotificationChannel::Email && config('notifications.email.enabled') !== true) {
            return false;
        }

        return TemplateCatalog::supports($key, $channel)
            && NotificationTemplate::query()->where('key', $key)->where('channel', $channel->value)->where('is_active', true)->exists();
    }

    /** "n•••@example.com" (rule 20 spirit: logs and admin screens never show a full address). */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
