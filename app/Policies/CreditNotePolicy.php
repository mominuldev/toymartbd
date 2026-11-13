<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Invoicing\Models\CreditNote;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Credit notes (P8 batch 2). They correct customer invoices only, so they follow the
 * customer-invoice permissions (InvoicePolicy, Sales): list and read with orders.view;
 * the PDF carries the customer's full phone and address, so printing also needs
 * customers.view_pii (rule 20). Never edited or deleted — no update/delete ability.
 */
final class CreditNotePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }

    public function view(User $user, CreditNote $creditNote): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }

    public function print(User $user, CreditNote $creditNote): bool
    {
        return $this->allows($user, Permission::OrdersView) && $this->allows($user, Permission::CustomersViewPii);
    }
}
