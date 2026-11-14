<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertDecision;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Mail\MonitoringAlertMail;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers an alert notice to the owner channel (P10): e-mail to `alerts.channels.mail` (or,
 * when that is empty, every active super admin), plus SMS through the configured SmsGateway
 * driver to `alerts.channels.sms` for alerts at or above `sms_min_severity`. Synchronous and
 * outside any transaction (rule 4). A failed channel is logged — counts only, never a
 * recipient (rule 20) — and does not stop the other channel.
 *
 * @phpstan-type Delivery array{mail: int, sms: int, errors: list<string>}
 */
final readonly class AlertNotifier
{
    public function __construct(private SmsGateway $sms) {}

    /** @return Delivery */
    public function send(AlertCheck $check, AlertResult $result, AlertDecision $decision, MonitoringSettings $settings, CarbonImmutable $now, bool $isTest = false): array
    {
        $kind = $decision === AlertDecision::Resolve ? 'resolved' : ($decision === AlertDecision::Remind ? 'reminder' : 'firing');
        $summary = $result->summary('en');
        $delivery = ['mail' => 0, 'sms' => 0, 'errors' => []];

        $emails = $this->emails($settings);
        if ($emails !== []) {
            try {
                Mail::to($emails)->send(new MonitoringAlertMail($check->name(), $check->severity()->value, $kind, $summary, $now->toIso8601String(), $isTest));
                $delivery['mail'] = count($emails);
            } catch (Throwable $e) {
                $delivery['errors'][] = 'mail: '.$e::class;
            }
        }

        $minimum = AlertSeverity::tryFrom($settings->string('alerts.channels.sms_min_severity', 'critical')) ?? AlertSeverity::Critical;
        if ($check->severity()->atLeast($minimum)) {
            $body = (string) __('monitoring.sms', [
                'tag' => $isTest ? '[TEST] ' : '',
                'kind' => strtoupper($kind),
                'name' => $check->name(),
                'summary' => $summary,
            ], 'en');
            foreach ($settings->list('alerts.channels.sms') as $raw) {
                $phone = PhoneNumber::tryParse($raw);
                if ($phone === null) {
                    $delivery['errors'][] = 'sms: invalid owner phone in settings';

                    continue;
                }
                try {
                    $sent = $this->sms->send($phone->e164(), mb_substr($body, 0, 300), 'monitoring.alert');
                    if ($sent->accepted) {
                        $delivery['sms']++;
                    } else {
                        $delivery['errors'][] = 'sms: rejected by provider';
                    }
                } catch (Throwable $e) {
                    $delivery['errors'][] = 'sms: '.$e::class;
                }
            }
        }

        Log::log($decision === AlertDecision::Resolve ? 'info' : 'warning', 'monitoring.alert', [
            'alert' => $check->name(),
            'severity' => $check->severity()->value,
            'kind' => $kind,
            'test' => $isTest,
            'summary' => $summary,
            'mail_recipients' => $delivery['mail'],
            'sms_recipients' => $delivery['sms'],
            'errors' => $delivery['errors'],
        ]);

        return $delivery;
    }

    /** @return list<string> */
    private function emails(MonitoringSettings $settings): array
    {
        $configured = array_values(array_filter($settings->list('alerts.channels.mail'), static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
        if ($configured !== []) {
            return $configured;
        }

        return User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->whereHas('roles', static fn (Builder $q): Builder => $q->where('name', Role::SuperAdmin->value))
            ->orderBy('id')
            ->pluck('email')
            ->map(static fn (mixed $e): string => (string) $e)
            ->values()
            ->all();
    }
}
