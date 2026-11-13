<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\Enums\Permission;
use App\Models\User;

/** Expense categories: read with finance.view, manage with finance.manage_expenses. Never hard-deleted. */
final class ExpenseCategoryPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses);
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses);
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $this->allows($user, Permission::FinanceManageExpenses);
    }
}
