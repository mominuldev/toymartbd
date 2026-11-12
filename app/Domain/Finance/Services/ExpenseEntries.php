<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Models\Expense;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;

/**
 * The ledger entries an expense writes (rules 8, 10, 11):
 *
 *   approved  → `expense`         out, recognised, cash iff paid at approval, dated expense_date
 *   paid later→ `expense_payment` out, cash only (not recognised again), dated paid_on
 *   voided    → `expense_void`    in, the exact mirror of what was posted, dated the void day
 *
 * The ledger type comes from the category ({@see \App\Domain\Finance\Models\ExpenseCategory::resolvedLedgerType()}).
 * A capitalised category (stock freight/customs, already in the landed cost) is never
 * recognised: its approval and void entries are cash-only, so the P&L sees the cost once,
 * as COGS when the goods sell.
 */
final class ExpenseEntries
{
    public const string SOURCE = 'expense';

    public const string SOURCE_PAYMENT = 'expense_payment';

    public const string SOURCE_VOID = 'expense_void';

    public static function approval(Expense $expense): LedgerEntry
    {
        return new LedgerEntry(
            type: $expense->category->resolvedLedgerType(),
            direction: Direction::Out,
            amount: $expense->amount,
            occurredOn: self::date($expense->expense_date->toDateString()),
            sourceType: self::SOURCE,
            sourceId: $expense->id,
            isCashMovement: $expense->isPaid(),
            isRecognisedRevenue: $expense->category->isRecognised(),
            categoryId: $expense->category_id,
            description: self::describe($expense),
        );
    }

    public static function payment(Expense $expense): LedgerEntry
    {
        return new LedgerEntry(
            type: $expense->category->resolvedLedgerType(),
            direction: Direction::Out,
            amount: $expense->amount,
            occurredOn: self::date(($expense->paid_on ?? $expense->expense_date)->toDateString()),
            sourceType: self::SOURCE_PAYMENT,
            sourceId: $expense->id,
            isCashMovement: true,
            isRecognisedRevenue: false,
            categoryId: $expense->category_id,
            description: 'Paid: '.self::describe($expense),
        );
    }

    /**
     * The reversal of everything the expense posted. Recognised always (the P&L entry is
     * undone); cash iff the expense had been paid (the cash is assumed refunded/returned).
     */
    public static function void(Expense $expense, CarbonImmutable $on): LedgerEntry
    {
        return new LedgerEntry(
            type: $expense->category->resolvedLedgerType(),
            direction: Direction::In,
            amount: $expense->amount,
            occurredOn: $on,
            sourceType: self::SOURCE_VOID,
            sourceId: $expense->id,
            isCashMovement: $expense->isPaid(),
            isRecognisedRevenue: $expense->category->isRecognised(),
            categoryId: $expense->category_id,
            description: 'Void: '.self::describe($expense),
        );
    }

    private static function date(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $ymd, DhakaDate::zone()) ?? DhakaDate::todayDate();
    }

    private static function describe(Expense $expense): string
    {
        return trim($expense->category->name.' — '.($expense->vendor ?? $expense->description ?? "expense #{$expense->id}"), ' —');
    }
}
