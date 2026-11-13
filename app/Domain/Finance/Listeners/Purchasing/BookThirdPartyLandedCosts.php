<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Purchasing;

use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\DTOs\ExpenseData;
use App\Domain\Finance\Enums\ExpenseCategoryType;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Purchasing\Events\LandedCostsPaidToThirdParty;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Freight, customs and other landed costs a third party charged on a purchase receipt
 * become ONE expense in the capitalised "Freight & customs (stock)" category, through the
 * normal expense flow (CreateExpense → pending → approval posts it).
 *
 * Accounting: these costs are already inside the stock's landed unit cost, so they reach
 * the P&L as COGS when the goods sell. The category is capitalised, so its ledger entries
 * are `supplier_payment`, `is_recognised_revenue = 0`, and `is_cash_movement = 1` once paid
 * — cash out only. Posting them as a P&L expense as well would count the cost twice.
 *
 * The expense starts pending like any other (an accountant approves it); when the receive
 * dialog said how the third party was paid, it is entered as paid on the receipt date.
 * Idempotent per receipt: the description starts with the GRN number, and a second event
 * for the same receipt creates nothing.
 */
final readonly class BookThirdPartyLandedCosts
{
    /** The capitalised category's English name (seeded by ExpenseCategoriesSeeder). */
    public const string CATEGORY = 'Freight & customs (stock)';

    public const string CATEGORY_BN = 'পরিবহন ও শুল্ক (স্টক)';

    public function __construct(private CreateExpense $create) {}

    public function handle(LandedCostsPaidToThirdParty $event): void
    {
        $amount = Money::of($event->total);
        if (! $amount->greaterThan(Money::zero())) {
            return;
        }

        $prefix = $event->receiptNumber.' · ';
        if (Expense::query()->where('description', 'like', addcslashes($prefix, '\\%_').'%')->exists()) {
            return;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $event->receivedOn, DhakaDate::zone()) ?: DhakaDate::todayDate();
        $method = $event->paymentMethod === null ? null : ExpensePaymentMethod::tryFrom($event->paymentMethod);

        $parts = array_filter([
            'freight' => $event->freight,
            'customs/duty' => $event->customsDuty,
            'other' => $event->otherCost,
        ], static fn (string $v): bool => bccomp($v, '0', 2) > 0);
        $breakdown = implode(', ', array_map(static fn (string $k, string $v): string => "{$k} {$v}", array_keys($parts), $parts));

        $this->create->handle(new ExpenseData(
            categoryId: self::category()->id,
            amount: $amount,
            expenseDate: $date,
            paymentMethod: $method,
            paidOn: $method === null ? null : $date,
            reference: $event->reference,
            vendor: $event->vendor,
            description: mb_substr($prefix.$event->purchaseNumber.' landed costs: '.$breakdown, 0, 1000),
            isRecurring: false,
            recurrence: null,
            orderId: null,
            shipmentId: null,
        ), null, $event->userId);
    }

    /** The capitalised stock-freight category, created on first use if the seeder has not run. */
    private static function category(): ExpenseCategory
    {
        $existing = ExpenseCategory::query()->where('capitalised', true)->where('is_active', true)->orderBy('id')->first();

        return $existing ?? ExpenseCategory::query()->create([
            'name' => self::CATEGORY,
            'name_bn' => self::CATEGORY_BN,
            'type' => ExpenseCategoryType::Cogs,
            'is_fixed' => false,
            'capitalised' => true,
            'is_active' => true,
        ]);
    }
}
