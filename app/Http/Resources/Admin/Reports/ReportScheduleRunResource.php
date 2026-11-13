<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One period's send of a schedule (the sent-log). `period_*` are Dhaka dates.
 *
 * @property ReportScheduleRun $resource
 */
#[SchemaName('ReportScheduleRun')]
final class ReportScheduleRunResource extends ApiResource
{
    /** @return array{id: int, period_from: string, period_to: string, status: string, recipients_sent: int, recipients_skipped: int, error: string|null, due_at: string|null, sent_at: string|null, created_at: string|null} */
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
        ];
    }
}
