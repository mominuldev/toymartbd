<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Purchasing\Models\SupplierPayment;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Supplier payments (finance). Seeing them needs finance.view; recording one needs
 * finance.record_supplier_payments. Immutable: no update or delete ability.
 */
final class SupplierPaymentPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, SupplierPayment $payment): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceRecordSupplierPayments);
    }
}
