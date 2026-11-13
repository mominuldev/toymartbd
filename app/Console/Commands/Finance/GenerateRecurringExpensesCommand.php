<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Domain\Finance\Actions\GenerateRecurringExpenses;
use App\Support\DhakaDate;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Generate the pending copies of recurring expenses for a Dhaka month (default: this month). Scheduled 1st, 00:30 Dhaka. */
final class GenerateRecurringExpensesCommand extends Command
{
    protected $signature = 'toymart:finance:recurring-expenses {--period= : Y-m, a Dhaka month}';

    protected $description = 'Create this month\'s pending copies of recurring expense templates (idempotent)';

    public function handle(GenerateRecurringExpenses $generate): int
    {
        $period = $this->option('period');
        $period = is_string($period) && $period !== '' ? $period : substr(DhakaDate::today(), 0, 7);

        try {
            $made = $generate->handle($period);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->info("Generated {$made} recurring expense(s) for {$period}.");

        return self::SUCCESS;
    }
}
