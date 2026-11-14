<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One period's send of a schedule (the sent-log). `period_*` are Dhaka dates. P9 adds each
 * recipient's copy and who resent the run.
 *
 * @property ReportScheduleRun $resource
 */
#[SchemaName('ReportScheduleRun')]
final class ReportScheduleRunResource extends ApiResource
{
    /** What the sent-log loads to show each recipient's copy and who resent it (P9). */
    public const array RELATIONS = ['deliveries.user', 'deliveries.resender', 'lastResender'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'period_from' => $r->period_from->toDateString(),
            'period_to' => $r->period_to->toDateString(),
            /** @var 'queued'|'sending'|'sent'|'skipped'|'failed' */
            'status' => $r->status->value,
            'recipients_sent' => $r->recipients_sent,
            'recipients_skipped' => $r->recipients_skipped,
            'error' => $r->error,
            'due_at' => $r->due_at?->toIso8601String(),
            'sent_at' => $r->sent_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            /** How many times staff resent this run (P9). */
            'resend_count' => $r->resend_count,
            /** @var string|null */
            'last_resent_at' => $r->last_resent_at?->toIso8601String(),
            /**
             * The staff member who last resent it — name only.
             *
             * @var array{id: int, name: string}|null
             */
            'last_resent_by' => $r->relationLoaded('lastResender') && $r->lastResender !== null ? ['id' => (int) $r->lastResender->id, 'name' => (string) $r->lastResender->name] : null,
            /**
             * Each recipient's copy (P9; empty on runs from before per-recipient records, or when
             * not loaded). Staff names only, never an email address.
             *
             * @var list<array{user_id: int, name: string, status: 'queued'|'sent'|'skipped'|'failed', attempts: int, error: string|null, sent_at: string|null, resent_at: string|null, resent_by: string|null}>
             */
            'deliveries' => $r->relationLoaded('deliveries') ? $r->deliveries->map(static fn ($d): array => [
                'user_id' => $d->user_id,
                'name' => (string) ($d->relationLoaded('user') ? $d->user->name : ''),
                'status' => $d->status->value,
                'attempts' => $d->attempts,
                'error' => $d->error,
                'sent_at' => $d->sent_at?->toIso8601String(),
                'resent_at' => $d->resent_at?->toIso8601String(),
                'resent_by' => $d->relationLoaded('resender') && $d->resender !== null ? (string) $d->resender->name : null,
            ])->values()->all() : [],
        ];
    }
}
