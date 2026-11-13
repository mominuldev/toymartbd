<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Schedules\ScheduleClock;
use App\Http\Resources\ApiResource;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A scheduled report email. Recipients are staff, shown by name and role only (no email
 * addresses in the list). `next_send_at` is the next Dhaka send instant, as UTC ISO-8601.
 *
 * @property ReportSchedule $resource
 */
#[SchemaName('ReportSchedule')]
final class ReportScheduleResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'id' => $s->id,
            'name' => $s->name,
            /** @var 'sales'|'pnl'|'couriers' */
            'report_type' => $s->report_type->value,
            /** @var 'daily'|'weekly'|'monthly' */
            'frequency' => $s->frequency->value,
            /** HH:MM, Asia/Dhaka */
            'send_time' => $s->send_time,
            /** @var 'en'|'bn' */
            'locale' => $s->locale,
            /** @var 'csv'|'xlsx' */
            'format' => $s->format->value,
            'is_active' => $s->is_active,
            /** @var list<array{id: int, name: string, roles: list<string>}> */
            'recipients' => $s->recipients->map(static fn ($user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'roles' => $user->getRoleNames()->values()->all(),
            ])->values()->all(),
            /** @var string|null */
            'next_send_at' => $s->is_active ? ScheduleClock::next($s, CarbonImmutable::now('UTC'))->toIso8601String() : null,
            /** @var array{id: int, period_from: string, period_to: string, status: 'queued'|'sending'|'sent'|'skipped'|'failed', recipients_sent: int, recipients_skipped: int, error: string|null, due_at: string|null, sent_at: string|null, created_at: string|null, resend_count: int, last_resent_at: string|null, last_resent_by: array{id: int, name: string}|null, deliveries: list<array{user_id: int, name: string, status: 'queued'|'sent'|'skipped'|'failed', attempts: int, error: string|null, sent_at: string|null, resent_at: string|null, resent_by: string|null}>}|null */
            'latest_run' => $s->relationLoaded('latestRun') && $s->latestRun !== null ? (new ReportScheduleRunResource($s->latestRun))->toArray($request) : null,
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
