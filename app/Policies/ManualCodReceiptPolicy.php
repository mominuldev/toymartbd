<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\ManualCodReceipt;
use App\Models\Enums\Permission;
use App\Models\User;

/** Recording cash received from a courier is COD reconciliation (finance.reconcile_cod). Receipts are insert-only. */
final class ManualCodReceiptPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, ManualCodReceipt $receipt): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceReconcileCod);
    }
}
