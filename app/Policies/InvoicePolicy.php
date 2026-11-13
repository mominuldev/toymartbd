<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Issued invoices (P8, PRD N-13). Which invoices a staff member sees follows the module
 * they belong to:
 *  - customer (sales) invoices: list with orders.view; the PDF carries the full phone and
 *    address, so printing also needs customers.view_pii (rule 20, as P4-ORD-08);
 *  - supplier (purchase) invoices: purchasing.view AND catalog.view_cost — a PO's amounts
 *    are cost (PurchasePolicy::viewCost).
 * Invoices are never edited or deleted, so there is no update/delete ability.
 */
final class InvoicePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->types($user) !== [];
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return in_array($invoice->type, $this->types($user), true);
    }

    public function print(User $user, Invoice $invoice): bool
    {
        return match ($invoice->type) {
            InvoiceType::Sales => $this->allows($user, Permission::OrdersView) && $this->allows($user, Permission::CustomersViewPii),
            InvoiceType::Purchase => $this->canSeePurchase($user),
        };
    }

    /** Print / issue a PO's supplier invoice. */
    public function printPurchase(User $user): bool
    {
        return $this->canSeePurchase($user);
    }

    /**
     * The invoice types this user may list.
     *
     * @return list<InvoiceType>
     */
    public function types(User $user): array
    {
        $types = [];
        if ($this->allows($user, Permission::OrdersView)) {
            $types[] = InvoiceType::Sales;
        }
        if ($this->canSeePurchase($user)) {
            $types[] = InvoiceType::Purchase;
        }

        return $types;
    }

    private function canSeePurchase(User $user): bool
    {
        return $this->allows($user, Permission::PurchasingView) && $this->allows($user, Permission::CatalogViewCost);
    }
}
