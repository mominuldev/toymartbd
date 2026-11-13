<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
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
use App\Support\DhakaDate;
use App\Support\PhoneNumber;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Birthday messages (P8). Scheduled daily at {@see AutomationSettings::BIRTHDAY_SEND_AT}
 * Dhaka; a run before that time sends nothing. Each customer is messaged at most ONCE per
 * Dhaka year (`automation_sends` UNIQUE on customer + year) — a re-run sends nothing.
 *
 * Only customers who CONSENTED to birthday messages (`customer_message_consents`, purpose
 * `birthday`; no row = not consented) and whose `date_of_birth` falls on today's Dhaka
 * date. A 29 February birthday is celebrated on 28 February in a non-leap year. The
 * birthday consent counts as the opt-in for this marketing message, but an explicit
 * opt-out of marketing on a channel still wins on that channel (WhatsApp first when
 * enabled, else SMS — rule 39; never OTP on WhatsApp, rule 32b, which this is not).
 *
 * The log row stores no phone (masked + hashed only, rule 20); SendNotification re-reads it.
 */
final readonly class SendBirthdayMessages
{
    /** @return array{queued: int, skipped: int} */
    public function handle(?DateTimeInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $tally = ['queued' => 0, 'skipped' => 0];
        $local = DhakaDate::local($now);
        if ($local->format('H:i') < AutomationSettings::BIRTHDAY_SEND_AT) {
            return $tally;
        }

        $kind = AutomationKind::Birthday;
        $year = $local->format('Y');
        $today = $local->format('m-d');
        $leapFallback = $today === '02-28' && ! $local->isLeapYear();

        // Consented customers only — a small set — then the birthday match in PHP (portable
        // across MySQL and SQLite, and exact about 29 February).
        $customers = Customer::query()
            ->whereNotNull('date_of_birth')
            ->whereIn('id', MessageConsent::query()->select('customer_id')
                ->where('purpose', MessageConsentPurpose::Birthday->value)->where('consented', true))
            ->whereNotIn('id', AutomationSend::query()->select('subject_id')
                ->where('automation', $kind->value)->where('period', $year))
            ->orderBy('id')
            ->get()
            ->filter(static function (Customer $c) use ($today, $leapFallback): bool {
                // Cast `date` on the model (a Carbon at runtime); narrowed rather than assumed.
                $dob = $c->getAttribute('date_of_birth');
                $birthday = $dob instanceof DateTimeInterface ? $dob->format('m-d') : null;

                return $birthday === $today || ($leapFallback && $birthday === '02-29');
            });

        foreach ($customers as $customer) {
            $status = $this->one($customer, $year, $now);
            if ($status !== null) {
                $tally[$status === AutomationSend::QUEUED ? 'queued' : 'skipped']++;
            }
        }

        return $tally;
    }

    private function one(Customer $customer, string $year, Carbon $now): ?string
    {
        $kind = AutomationKind::Birthday;

        try {
            [$send, $log] = DB::transaction(function () use ($customer, $year, $now, $kind): array {
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
                    'subject_id' => $customer->id,
                    'period' => $year,
                    'customer_id' => $customer->id,
                    'status' => $skip === null ? AutomationSend::QUEUED : AutomationSend::SKIPPED,
                    'skip_reason' => $skip,
                ]);

                $log = null;
                if ($skip === null) {
                    $log = NotificationLog::query()->create([
                        'channel' => $channel,
                        'template' => $kind->template(),
                        'category' => NotificationCategory::Marketing,
                        'locale' => $customer->preferred_language === 'en' ? 'en' : 'bn',
                        'recipient' => $phone->masked(),
                        'recipient_hash' => NotificationLog::hashRecipient($phone->e164()),
                        'notifiable_type' => NotificationLog::NOTIFIABLE_CUSTOMER,
                        'notifiable_id' => $customer->id,
                        'payload' => [
                            'name' => self::firstName($customer->name),
                            'store_url' => (string) config('notifications.store_url', 'toymartbd.com'),
                        ],
                        'status' => NotificationStatus::Queued,
                        'queued_at' => $now,
                    ]);
                    $send->notification_log_id = $log->id;
                    $send->save();
                }

                return [$send, $log];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // another run took this customer this year
        }

        if ($log !== null) {
            SendNotification::dispatch($log->id);
        }

        return $send->status;
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
