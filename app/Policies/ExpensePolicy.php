<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\Expense;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Expenses (PRD N-04). Read with finance.view; enter, edit, pay and void with
 * finance.manage_expenses. Approval and rejection additionally need a second person: the
 * one who entered the expense cannot approve it (super admin — the owner — bypasses every
 * policy via Gate::before, so a one-person shop can still close its books).
 */
final class ExpensePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses);
    }

    public function approve(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses) && $expense->created_by !== $user->id;
    }

    public function reject(User $user, Expense $expense): bool
    {
        return $this->approve($user, $expense);
    }

    public function void(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses);
    }

    public function pay(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses);
    }
}
