<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\ExpenseRecurrence;
use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Enums\LedgerEntryType as T;
use App\Domain\Finance\Models\Expense;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The Expenses screen's stat cards (Figma Expenses 222:13676), all computed here (rule 1):
 * approved spend in the period and its two largest categories, month-to-date spend, the
 * recurring commitment per month, ad spend ÷ delivered revenue for the period (from the
 * ledger: marketing ÷ (sale_revenue − discount_given)), and how many expenses await approval.
 */
final readonly class ExpenseSummary
{
    /**
     * @return array{from: string, to: string, period_total: Money, period_top: list<array{category_id: int, name: string, name_bn: string|null, amount: Money}>, month_to_date: Money, recurring_monthly: Money, ad_spend_ratio: string|null, pending_count: int}
     */
    public function for(string $from, string $to): array
    {
        // Capitalised stock costs (freight/customs inside landed cost) are not operating spend.
        $approved = static fn () => Expense::query()->where('status', ExpenseStatus::Approved->value)
            ->whereHas('category', static fn ($c) => $c->where('capitalised', false));

        $byCategory = $approved()->whereBetween('expense_date', [$from, $to])
            ->join('expense_categories as c', 'c.id', '=', 'expenses.category_id')
            ->groupBy('expenses.category_id', 'c.name', 'c.name_bn')
            ->selectRaw('expenses.category_id, c.name, c.name_bn, SUM(expenses.amount) as total')
            ->orderByDesc('total')
            ->get();

        $total = Money::zero();
        $top = [];
        foreach ($byCategory as $row) {
            $amount = self::money($row->getAttribute('total'));
            $total = $total->plus($amount);
            if (count($top) < 2) {
                $top[] = ['category_id' => (int) $row->getAttribute('category_id'), 'name' => (string) $row->getAttribute('name'), 'name_bn' => $row->getAttribute('name_bn'), 'amount' => $amount];
            }
        }

        $today = DhakaDate::today();
        $monthToDate = self::money($approved()->whereBetween('expense_date', [substr($today, 0, 8).'01', $today])->sum('amount'));

        $recurring = Money::zero();
        foreach ($approved()->where('is_recurring', true)->whereNull('recurrence_parent_id')->get(['amount', 'recurrence']) as $template) {
            $recurring = $recurring->plus(match ($template->recurrence) {
                ExpenseRecurrence::Quarterly => Money::of(bcdiv($template->amount->toDecimal(), '3', 4)),
                ExpenseRecurrence::Yearly => Money::of(bcdiv($template->amount->toDecimal(), '12', 4)),
                default => $template->amount,
            });
        }

        $ledger = DB::table('financial_transactions')->whereBetween('occurred_on', [$from, $to])
            ->where('is_recognised_revenue', true)
            ->whereIn('type', [T::Marketing->value, T::SaleRevenue->value, T::DiscountGiven->value])
            ->groupBy('type')
            ->selectRaw("type, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as net")
            ->pluck('net', 'type');
        $marketing = self::money($ledger[T::Marketing->value] ?? 0)->negate();
        $revenue = self::money($ledger[T::SaleRevenue->value] ?? 0)->plus(self::money($ledger[T::DiscountGiven->value] ?? 0));
        $ratio = $revenue->greaterThan(Money::zero())
            ? Money::of(bcdiv(bcmul($marketing->toDecimal(), '100', 6), $revenue->toDecimal(), 4))->toDecimal()
            : null;

        return [
            'from' => $from,
            'to' => $to,
            'period_total' => $total,
            'period_top' => $top,
            'month_to_date' => $monthToDate,
            'recurring_monthly' => $recurring,
            'ad_spend_ratio' => $ratio,
            'pending_count' => Expense::query()->where('status', ExpenseStatus::Pending->value)->count(),
        ];
    }

    private static function money(mixed $value): Money
    {
        return match (true) {
            is_float($value) => Money::of(number_format($value, 2, '.', '')),
            is_int($value), is_string($value) => Money::of($value),
            default => Money::zero(),
        };
    }
}
