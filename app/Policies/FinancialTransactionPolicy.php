<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\FinancialTransaction;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * The ledger is read-only for everyone (rule 8): no create/update/delete ability exists —
 * entries are written by Finance Actions, never by an endpoint. Reading needs finance.view
 * (super admin, manager, accountant).
 */
final class FinancialTransactionPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, FinancialTransaction $entry): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    /** COD receivables (recognised − received). */
    public function viewReceivables(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }
}
