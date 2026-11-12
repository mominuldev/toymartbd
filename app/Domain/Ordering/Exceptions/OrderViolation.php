<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use LogicException;

/** A programming error against order invariants — never a customer-facing condition. */
final class OrderViolation extends LogicException
{
    public static function neverDeleted(): self
    {
        return new self('Orders are never deleted, only status-changed (rule 12).');
    }

    public static function costSnapshotImmutable(): self
    {
        return new self('order_items.cost_price_snapshot is written once at creation and never recalculated (rule 7).');
    }

    public static function appendOnly(): self
    {
        return new self('order_events is append-only: an event is never updated or deleted.');
    }

    public static function statusOutsideStateMachine(): self
    {
        return new self('Order statuses change only through OrderTransitions (P4-ORD-03).');
    }

    public static function outsideTransaction(): self
    {
        return new self('Order writes must run inside the calling Action\'s DB::transaction (rule 3).');
    }
}
