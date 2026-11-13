<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Cart\Models\Cart;
use App\Domain\Notification\CartRecovery\RecoveryConsent;
use App\Domain\Notification\Enums\CartRecoverySkip;
use App\Domain\Notification\Enums\CartRecoveryStatus;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Jobs\SendCartRecoveryMessage;
use App\Domain\Notification\Models\CartRecovery;
use App\Domain\Notification\Models\NotificationLog;
use App\Support\DhakaDate;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Abandoned-cart detection (P7, docs/api/cart-recovery.md). A cart is abandoned when it
 * has items, a phone or a customer, is still open, and neither it nor its contact has
 * changed for `after_minutes` (but within `max_age_hours`). Each one gets exactly one
 * `cart_recoveries` row (UNIQUE cart_id — a second run, or two schedulers, cannot message
 * a cart twice): `messaged`, with a queued SMS log row and a job dispatched after
 * commit, or `skipped` with the reason (consent, cooldown, already ordered …).
 *
 * Runs outside the Dhaka quiet hours only. One transaction per cart.
 */
final readonly class DetectAbandonedCarts
{
    public const int BATCH = 200;

    public function __construct(private RecoveryConsent $consent) {}

    /** @return array{messaged: int, skipped: int} */
    public function handle(?DateTimeInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $tally = ['messaged' => 0, 'skipped' => 0];
        if (! (bool) config('cart_recovery.enabled', true) || self::quiet($now)) {
            return $tally;
        }

        $idleSince = $now->copy()->subMinutes(max(1, (int) config('cart_recovery.after_minutes', 60)));
        $oldest = $now->copy()->subHours(max(1, (int) config('cart_recovery.max_age_hours', 48)));

        $carts = Cart::query()
            ->whereNull('converted_at')
            ->whereNull('merged_into_cart_id')
            ->where('expires_at', '>', $now)
            ->where('item_count', '>', 0)
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('customer_id'))
            ->whereBetween('updated_at', [$oldest, $idleSince])
            ->where(fn ($q) => $q->whereNull('contact_captured_at')->orWhere('contact_captured_at', '<=', $idleSince))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cart_recoveries')->whereColumn('cart_recoveries.cart_id', 'carts.id'))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($carts as $cart) {
            $status = $this->one($cart, $now);
            if ($status !== null) {
                $tally[$status === CartRecoveryStatus::Messaged ? 'messaged' : 'skipped']++;
            }
        }

        return $tally;
    }

    private function one(Cart $cart, Carbon $now): ?CartRecoveryStatus
    {
        try {
            [$recovery, $log] = DB::transaction(function () use ($cart, $now): array {
                $check = $this->consent->check($cart);
                $phone = $check['phone'];
                $customer = $check['customer'];
                $value = $cart->subtotal->minus($cart->discount_total);
                $locale = in_array($cart->locale, ['en', 'bn'], true) ? (string) $cart->locale
                    : (in_array($customer?->preferred_language, ['en', 'bn'], true) ? (string) $customer->preferred_language : 'bn');

                $skip = $check['skip']
                    ?? match (true) {
                        $value->lessThan(Money::of((string) config('cart_recovery.min_cart_value', '0.00'))) => CartRecoverySkip::BelowMinimum,
                        $phone !== null && self::recentlyMessaged(NotificationLog::hashRecipient($phone->e164()), $now) => CartRecoverySkip::Cooldown,
                        $phone !== null && self::orderedSince($phone->e164(), $cart->updated_at ?? $now) => CartRecoverySkip::AlreadyOrdered,
                        ! QueueOrderNotification::offered(CartRecovery::TEMPLATE, NotificationChannel::Sms) => CartRecoverySkip::TemplateInactive,
                        default => null,
                    };

                $recovery = CartRecovery::query()->create([
                    'cart_id' => $cart->id,
                    'customer_id' => $customer?->id,
                    'phone_hash' => $phone === null ? null : NotificationLog::hashRecipient($phone->e164()),
                    'status' => $skip === null ? CartRecoveryStatus::Messaged : CartRecoveryStatus::Skipped,
                    'skip_reason' => $skip,
                    'locale' => $locale,
                    'cart_value' => $value,
                    'item_count' => $cart->item_count,
                    'abandoned_at' => $cart->updated_at ?? $now,
                ]);

                $log = null;
                if ($skip === null && $phone !== null) {
                    $log = NotificationLog::query()->create([
                        'channel' => NotificationChannel::Sms,
                        'template' => CartRecovery::TEMPLATE,
                        'category' => NotificationCategory::Marketing,
                        'locale' => $locale,
                        'recipient' => $phone->masked(),
                        'recipient_hash' => NotificationLog::hashRecipient($phone->e164()),
                        'notifiable_type' => CartRecovery::NOTIFIABLE,
                        'notifiable_id' => $recovery->id,
                        // No phone, no address, no token (rule 20): the link is minted at send time.
                        'payload' => ['name' => self::firstName($customer?->name), 'item_count' => (string) $cart->item_count],
                        'status' => NotificationStatus::Queued,
                        'queued_at' => $now,
                    ]);
                }

                return [$recovery, $log];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // another run took this cart
        }

        if ($log !== null) {
            SendCartRecoveryMessage::dispatch($log->id);
        }

        return $recovery->status;
    }

    private static function recentlyMessaged(string $phoneHash, Carbon $now): bool
    {
        return CartRecovery::query()
            ->where('phone_hash', $phoneHash)
            ->where('status', CartRecoveryStatus::Messaged->value)
            ->where('created_at', '>=', $now->copy()->subHours(max(1, (int) config('cart_recovery.phone_cooldown_hours', 72))))
            ->exists();
    }

    private static function orderedSince(string $e164, DateTimeInterface $since): bool
    {
        return DB::table('orders')->where('shipping_phone', $e164)->where('created_at', '>=', $since)->exists();
    }

    private static function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : (string) preg_split('/\s+/u', $name)[0];
    }

    /** Inside the Dhaka quiet hours (a window that may wrap midnight). */
    public static function quiet(DateTimeInterface $now): bool
    {
        $hours = (array) config('cart_recovery.quiet_hours', []);
        $from = (string) ($hours['from'] ?? '');
        $to = (string) ($hours['to'] ?? '');
        if ($from === '' || $to === '' || $from === $to) {
            return false;
        }
        $t = DhakaDate::local($now)->format('H:i');

        return $from < $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to);
    }
}
