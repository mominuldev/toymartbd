<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Notification\Enums\AutomationKind;
use App\Domain\Notification\Enums\AutomationSkip;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\AutomationSend;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Notification\Services\AutomationSettings;
use App\Domain\Notification\Services\NotificationPreferences;
use App\Domain\Notification\Support\QuietHours;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\PhoneNumber;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Review-request automation (P8). Every delivered (or completed) order is asked ONCE for a
 * review, `delay_days` after delivery, by the `review.request` template — WhatsApp utility
 * first when WhatsApp is on, else SMS (QueueOrderNotification decides, rule 39) — with a
 * link to the storefront review form on the first item's product page.
 *
 * A review request is a MARKETING message (NotificationCategory): sent only to a customer who
 * opted in on a messaging channel; an explicit opt-out always wins. Nothing is sent inside
 * the Dhaka quiet hours — the hourly run picks the order up once they end. Orders delivered
 * more than delay + {@see AutomationSettings::REVIEW_LOOKBACK_DAYS} days ago are never
 * asked (so switching it on does not message last year's customers).
 *
 * Idempotent: each order gets one `automation_sends` row (UNIQUE), written in the same
 * transaction as its notification log rows; a second run, or a second scheduler, sends nothing.
 */
final readonly class SendReviewRequests
{
    public const int BATCH = 200;

    public function __construct(
        private AutomationSettings $settings,
        private NotificationPreferences $preferences,
        private QueueOrderNotification $queue,
    ) {}

    /** @return array{queued: int, skipped: int} */
    public function handle(?DateTimeInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $tally = ['queued' => 0, 'skipped' => 0];
        $quiet = $this->settings->quietHours();
        if (QuietHours::contains($now, $quiet['from'], $quiet['to'])) {
            return $tally;
        }

        $delay = $this->settings->reviewDelayDays();
        $latest = $now->copy()->subDays($delay);
        $earliest = $latest->copy()->subDays(AutomationSettings::REVIEW_LOOKBACK_DAYS);
        $kind = AutomationKind::ReviewRequest;

        $orders = Order::query()
            ->with(['customer', 'items'])
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->whereBetween('delivered_at', [$earliest, $latest])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('automation_sends')
                ->where('automation_sends.automation', $kind->value)
                ->whereColumn('automation_sends.subject_id', 'orders.id'))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($orders as $order) {
            $status = $this->one($order);
            if ($status !== null) {
                $tally[$status === AutomationSend::QUEUED ? 'queued' : 'skipped']++;
            }
        }

        return $tally;
    }

    private function one(Order $order): ?string
    {
        $kind = AutomationKind::ReviewRequest;
        $customer = $order->customer;
        $locale = $customer->preferred_language === 'en' ? 'en' : 'bn';

        try {
            return DB::transaction(function () use ($order, $customer, $locale, $kind): string {
                $skip = match (true) {
                    $customer->status === CustomerStatus::Blocked => AutomationSkip::Blocked,
                    PhoneNumber::tryParse($order->shipping_phone) === null => AutomationSkip::NoPhone,
                    ! $this->anyChannelOffered($kind->template()) => AutomationSkip::TemplateInactive,
                    default => $this->consentSkip($customer->id),
                };

                $send = AutomationSend::query()->create([
                    'automation' => $kind,
                    'subject_id' => $order->id,
                    'period' => '',
                    'customer_id' => $customer->id,
                    'status' => $skip === null ? AutomationSend::QUEUED : AutomationSend::SKIPPED,
                    'skip_reason' => $skip,
                ]);

                if ($skip === null) {
                    $logs = $this->queue->handle($order->id, $kind->template(), ['review_url' => self::reviewUrl($order, $locale)]);
                    $queued = array_values(array_filter($logs, static fn ($log): bool => $log->status === NotificationStatus::Queued));
                    $send->notification_log_id = ($queued[0] ?? $logs[0] ?? null)?->id;
                    $send->save();
                }

                return $send->status;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // another run took this order
        }
    }

    /** Null when some messaging channel may carry a marketing message to this customer. */
    private function consentSkip(int $customerId): ?AutomationSkip
    {
        foreach (QueueOrderNotification::messagingChannels() as $channel) {
            if ($this->preferences->allows($customerId, $channel, NotificationCategory::Marketing)) {
                return null;
            }
        }

        $explicitOff = NotificationPreference::query()
            ->where('customer_id', $customerId)
            ->where('category', NotificationCategory::Marketing->value)
            ->where('enabled', false)
            ->exists();

        return $explicitOff ? AutomationSkip::OptedOut : AutomationSkip::NoConsent;
    }

    private function anyChannelOffered(string $key): bool
    {
        foreach (QueueOrderNotification::messagingChannels() as $channel) {
            if (QueueOrderNotification::offered($key, $channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The storefront review form: the first item's product page, `#reviews` (the PDP's
     * "Write a review" form, P4-CAT-12), Bangla under `/bn` (rule 35); the slug is shared by
     * both locales (rule 37). Falls back to the customer's order page when the product is gone.
     */
    public static function reviewUrl(Order $order, string $locale): string
    {
        $base = rtrim((string) (config('services.storefront.url') ?: config('cart_recovery.storefront_url', 'https://toymartbd.com')), '/')
            .($locale === 'bn' ? '/bn' : '');
        $productId = $order->items->first(static fn ($item): bool => $item->product_id !== null)?->product_id;
        $slug = $productId === null ? null : Product::query()->whereKey($productId)->value('slug');

        return is_string($slug) && $slug !== ''
            ? $base.'/products/'.$slug.'#reviews'
            : $base.'/account/orders/'.rawurlencode($order->number);
    }
}
