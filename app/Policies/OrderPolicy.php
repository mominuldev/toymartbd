<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Orders (P4-ORD-01..10). There is no delete ability: orders are never deleted (rule 12).
 * `viewPii` decides whether the full phone and address are shown or masked (rule 20).
 */
final class OrderPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::OrdersCreate);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->allows($user, Permission::OrdersUpdate);
    }

    public function confirm(User $user, Order $order): bool
    {
        return $this->allows($user, Permission::OrdersConfirm);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->allows($user, Permission::OrdersCancel);
    }

    /** Bulk confirm from the board: the same permission as confirming one order. */
    public function confirmMany(User $user): bool
    {
        return $this->allows($user, Permission::OrdersConfirm);
    }

    /** Hold, release from hold, start processing, mark ready to ship. */
    public function progress(User $user, Order $order): bool
    {
        return $this->allows($user, Permission::OrdersUpdate);
    }

    /** Bulk "mark packed" from the board (P4-ORD-08): the same permission as one move. */
    public function progressMany(User $user): bool
    {
        return $this->allows($user, Permission::OrdersUpdate);
    }

    /**
     * Manual fulfilment (P5, until Phase 6 courier booking): mark shipped (consumes stock)
     * and mark delivered (recognises revenue, collects COD). Moves stock and money, so it
     * needs shipments.book on top of orders.update.
     */
    public function fulfil(User $user, Order $order): bool
    {
        return $this->fulfilMany($user);
    }

    /** Bulk mark shipped / delivered from the board: the same permissions as one order. */
    public function fulfilMany(User $user): bool
    {
        return $this->allows($user, Permission::OrdersUpdate) && $this->allows($user, Permission::ShipmentsBook);
    }

    /**
     * Invoice / packing slip PDF (P4-ORD-08). The slip carries the full phone and street
     * address, so it needs customers.view_pii as well as orders.view (rule 20).
     */
    public function printInvoices(User $user): bool
    {
        return $this->allows($user, Permission::OrdersView) && $this->allows($user, Permission::CustomersViewPii);
    }

    /** Append an internal note (P4-ORD-05). */
    public function addNote(User $user, Order $order): bool
    {
        return $this->allows($user, Permission::OrdersUpdate);
    }

    /** Full phone and address; without it the API masks both. */
    public function viewPii(User $user): bool
    {
        return $this->allows($user, Permission::CustomersViewPii);
    }
}
