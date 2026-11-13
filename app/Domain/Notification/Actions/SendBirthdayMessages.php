<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerChild;
use App\Domain\Marketing\Contracts\IssuedCoupon;
use App\Domain\Marketing\Contracts\PersonalCouponIssuer;
use App\Domain\Notification\Enums\AutomationKind;
use App\Domain\Notification\Enums\AutomationSkip;
use App\Domain\Notification\Enums\MessageConsentPurpose;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Jobs\SendNotification;
use App\Domain\Notification\Models\AutomationSend;
use App\Domain\Notification\Models\MessageConsent;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Notification\Services\AutomationSettings;
use App\Domain\Notification\Support\QuietHours;
use App\Support\DhakaDate;
use App\Support\Locale\Formatter;
use App\Support\Money;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Birthday reminders (P8 batch 3, owner decision 2026-11-13): to the PARENT, before each of
 * their CHILDREN's birthdays (`customer_children`), with a personal gift coupon when the offer
 * is on. Run hourly; sends nothing before {@see AutomationSettings::BIRTHDAY_SEND_AT} Dhaka or
 * inside the Dhaka quiet hours.
 *
 * A child is due once their next birthday (29 February → 28 February in a non-leap year) is at
 * most `lead_days` away (default 7, a PLACEHOLDER). "At most", not "exactly": a missed run, quiet
 * hours or a child added three days before still gets the reminder. Each child is reminded at
 * most ONCE per birthday — `automation_sends` UNIQUE on (birthday, child, year of that birthday),
 * so a re-run, two schedulers or a replay send nothing and mint no second coupon.
 *
 * Only for customers who CONSENTED (`customer_message_consents` purpose `birthday`, which now
 * covers "birthday reminders with offers"; no row = not consented). That purpose consent is the
 * explicit opt-in for this marketing message, but an explicit marketing opt-out on a channel
 * still wins on that channel (as for cart reminders: explicit opt-in, never against an opt-out).
 * WhatsApp first when enabled, else SMS (rule 39).
 *
 * The coupon is minted through Marketing's {@see PersonalCouponIssuer} inside this transaction,
 * after the UNIQUE send row — so it exists only if the send does. Nothing external happens in
 * the transaction; the message is queued after commit (rule 4). The log row stores no phone
 * (masked + hashed, rule 20); the child's name is in the payload only to render the message
 * and is blanked when the parent removes the child (ForgetRemovedChild).
 */
final readonly class SendBirthdayMessages
{
    public function __construct(
        private AutomationSettings $settings,
        private PersonalCouponIssuer $coupons,
    ) {}

    /** @return array{queued: int, skipped: int} */
    public function handle(?DateTimeInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $tally = ['queued' => 0, 'skipped' => 0];
        $local = DhakaDate::local($now);
        $quiet = $this->settings->quietHours();
        if ($local->format('H:i') < AutomationSettings::BIRTHDAY_SEND_AT || QuietHours::contains($now, $quiet['from'], $quiet['to'])) {
            return $tally;
        }

        $today = $local->startOfDay();
        $lead = $this->settings->birthdayLeadDays();
        $kind = AutomationKind::Birthday;

        // Consenting parents only — a small set — then the date window in PHP (portable across
        // MySQL and SQLite, and exact about 29 February and the turn of the year).
        $children = CustomerChild::query()
            ->whereIn('customer_id', MessageConsent::query()->select('customer_id')
                ->where('purpose', MessageConsentPurpose::Birthday->value)->where('consented', true))
            ->orderBy('id')
            ->get();

        foreach ($children as $child) {
            $birthday = $child->nextBirthday($today);
            if ((int) round($today->diffInDays($birthday)) > $lead) {
                continue;
            }
            $period = (string) $birthday->year;
            if (AutomationSend::query()->where('automation', $kind->value)->where('subject_id', $child->id)->where('period', $period)->exists()) {
                continue;
            }

            $status = $this->one($child, $birthday, $period, $now);
            if ($status !== null) {
                $tally[$status === AutomationSend::QUEUED ? 'queued' : 'skipped']++;
            }
        }

        return $tally;
    }

    private function one(CustomerChild $child, CarbonImmutable $birthday, string $period, Carbon $now): ?string
    {
        $customer = Customer::query()->find($child->customer_id);
        if ($customer === null) {
            return null;
        }

        try {
            $result = $this->decide($customer, $child, $birthday, $period, $now);
        } catch (UniqueConstraintViolationException) {
            return null; // another run took this child's birthday — its transaction, coupon included, is the only one
        }

        [$send, $log] = $result;
        if ($log !== null) {
            SendNotification::dispatch($log->id);
        }

        return $send->status;
    }

    /**
     * One transaction: the UNIQUE send row first (a concurrent run fails there and rolls back
     * everything, coupon included), then — if not skipped — the coupon and the log row.
     *
     * @return array{0: AutomationSend, 1: NotificationLog|null}
     */
    private function decide(Customer $customer, CustomerChild $child, CarbonImmutable $birthday, string $period, Carbon $now): array
    {
        $kind = AutomationKind::Birthday;

        return DB::transaction(function () use ($customer, $child, $birthday, $period, $now, $kind): array {
            $phone = PhoneNumber::tryParse($customer->phone);
            $offered = array_values(array_filter(
                QueueOrderNotification::messagingChannels(),
                static fn (NotificationChannel $c): bool => QueueOrderNotification::offered($kind->template(), $c),
            ));
            $channel = null;
            foreach ($offered as $candidate) {
                if (! self::optedOut($customer->id, $candidate)) {
                    $channel = $candidate;
                    break;
                }
            }

            $skip = match (true) {
                $customer->status === CustomerStatus::Blocked => AutomationSkip::Blocked,
                $phone === null => AutomationSkip::NoPhone,
                $offered === [] => AutomationSkip::TemplateInactive,
                $channel === null => AutomationSkip::OptedOut,
                default => null,
            };

            $send = AutomationSend::query()->create([
                'automation' => $kind,
                'subject_id' => $child->id,
                'period' => $period,
                'customer_id' => $customer->id,
                'status' => $skip === null ? AutomationSend::QUEUED : AutomationSend::SKIPPED,
                'skip_reason' => $skip,
            ]);
            if ($skip !== null) {
                return [$send, null];
            }

            $terms = $this->settings->birthdayCouponTerms();
            $coupon = $terms === null ? null : $this->coupons->issue($customer->id, $terms, $now);
            $locale = $customer->preferred_language === 'en' ? 'en' : 'bn';

            $log = NotificationLog::query()->create([
                'channel' => $channel,
                'template' => $kind->template(),
                'category' => NotificationCategory::Marketing,
                'locale' => $locale,
                'recipient' => $phone->masked(),
                'recipient_hash' => NotificationLog::hashRecipient($phone->e164()),
                'notifiable_type' => NotificationLog::NOTIFIABLE_CUSTOMER,
                'notifiable_id' => $customer->id,
                'payload' => self::payload($customer, $child, $birthday, $coupon, $terms?->type, $terms?->value, $channel, $locale),
                'status' => NotificationStatus::Queued,
                'queued_at' => $now,
            ]);
            $send->notification_log_id = $log->id;
            $send->coupon_id = $coupon?->id;
            $send->save();

            return [$send, $log];
        });
    }

    /**
     * The template variables. Western digits in both locales (rule 36). `offer_sentence` is the
     * whole coupon line, or empty without an offer — WhatsApp then gets a non-empty line.
     *
     * @return array<string, string>
     */
    private static function payload(Customer $customer, CustomerChild $child, CarbonImmutable $birthday, ?IssuedCoupon $coupon, ?string $type, ?Money $value, NotificationChannel $channel, string $locale): array
    {
        $code = '';
        $expiry = '';
        $sentence = '';
        if ($coupon !== null && $value !== null) {
            $code = $coupon->code;
            // The last day it works, in Dhaka: the coupon ends at the start of the next day.
            $expiry = Formatter::date($coupon->endsAt->subSecond(), $locale);
            $amount = $type === 'percent' ? rtrim(rtrim($value->toDecimal(), '0'), '.') : substr(Formatter::smsMoney($value), 4);
            $offer = (string) trans($type === 'percent' ? 'notifications.birthday.offer_percent' : 'notifications.birthday.offer_fixed', ['value' => $amount], $locale);
            $sentence = (string) trans('notifications.birthday.offer_sentence', ['code' => $code, 'offer' => $offer, 'expiry' => $expiry], $locale);
        } elseif ($channel === NotificationChannel::WhatsApp) {
            $sentence = (string) trans('notifications.birthday.no_offer_whatsapp', [], $locale);
        }

        return [
            'name' => self::firstName($customer->name),
            'child_possessive' => self::possessive($child->name, $locale),
            'birthday_date' => Formatter::date($birthday, $locale),
            'offer_sentence' => $sentence,
            'coupon_code' => $code,
            'coupon_expiry' => $expiry,
            'store_url' => (string) config('notifications.store_url', 'toymartbd.com'),
        ];
    }

    /** "Ayaan's" / "আয়ান-এর", or "your child's" / "আপনার সন্তানের" without a name. */
    public static function possessive(?string $childName, string $locale): string
    {
        $name = trim((string) $childName);

        return $name === ''
            ? (string) trans('notifications.birthday.child_possessive_unnamed', [], $locale)
            : (string) trans('notifications.birthday.child_possessive', ['name' => $name], $locale);
    }

    /** An explicit "no marketing on this channel" always wins over the birthday consent. */
    private static function optedOut(int $customerId, NotificationChannel $channel): bool
    {
        return NotificationPreference::query()
            ->where('customer_id', $customerId)
            ->where('channel', $channel->value)
            ->where('category', NotificationCategory::Marketing->value)
            ->where('enabled', false)
            ->exists();
    }

    private static function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : (string) preg_split('/\s+/u', $name)[0];
    }
}
