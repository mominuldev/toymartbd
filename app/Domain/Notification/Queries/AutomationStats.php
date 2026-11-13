<?php

declare(strict_types=1);

namespace App\Domain\Notification\Queries;

use App\Domain\Notification\Enums\AutomationKind;
use App\Domain\Notification\Models\AutomationSend;
use Illuminate\Support\Carbon;

/** What each automation decided over the last N days (P8): queued vs skipped, by reason. */
final class AutomationStats
{
    /** @return array<string, array{queued: int, skipped: int, skip_reasons: array<string, int>}> */
    public function lastDays(int $days = 30): array
    {
        $rows = AutomationSend::query()
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->selectRaw('automation, status, skip_reason, COUNT(*) as aggregate')
            ->groupBy('automation', 'status', 'skip_reason')
            ->toBase()
            ->get();

        $stats = [];
        foreach (AutomationKind::cases() as $kind) {
            $stats[$kind->value] = ['queued' => 0, 'skipped' => 0, 'skip_reasons' => []];
        }
        foreach ($rows as $row) {
            $kind = (string) $row->automation;
            if (! isset($stats[$kind])) {
                continue;
            }
            $count = (int) $row->aggregate;
            if ($row->status === AutomationSend::QUEUED) {
                $stats[$kind]['queued'] += $count;
            } else {
                $stats[$kind]['skipped'] += $count;
                $reason = (string) ($row->skip_reason ?? 'unknown');
                $stats[$kind]['skip_reasons'][$reason] = ($stats[$kind]['skip_reasons'][$reason] ?? 0) + $count;
            }
        }

        return $stats;
    }
}
