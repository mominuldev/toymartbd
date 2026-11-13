<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\DailyFinancialSummary;
use App\Models\Enums\Permission;
use App\Models\User;

/** The nightly rollup is finance data (finance.view). Written only by the rollup job. */
final class DailyFinancialSummaryPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, DailyFinancialSummary $summary): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }
}
