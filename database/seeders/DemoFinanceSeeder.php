<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Finance\Actions\ApproveExpense;
use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\GenerateRecurringExpenses;
use App\Domain\Finance\Actions\MarkExpensePaid;
use App\Domain\Finance\Actions\RejectExpense;
use App\Domain\Finance\Actions\VoidExpense;
use App\Domain\Finance\DTOs\ExpenseData;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Enums\ExpenseRecurrence;
use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Services\DailySummaryRollup;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo expenses (local/testing only, from DemoDataSeeder) across `demo.history_days`,
 * entered and approved the way the business would (PRD N-04):
 *
 *  - the accountant enters every expense; the manager approves or rejects it — never the
 *    person who entered it (ExpensePolicy's segregation of duties);
 *  - every state shows: approved and paid, approved then paid later (accrual + cash-only
 *    `expense_payment`), approved and still unpaid, pending approval, rejected with a
 *    reason, and voided (the reversing entry dated the void day);
 *  - rent, salaries and the broadband bill are recurring monthly templates;
 *    GenerateRecurringExpenses runs on the 1st of each later month as the scheduler would,
 *    the past months' copies are approved and paid, this month's wait for approval;
 *  - the capitalised freight/customs expenses that DemoPurchasingSeeder's receipts raised
 *    are approved (cash out only, never P&L);
 *  - when orders exist (DemoOrdersSeeder runs earlier), two gift-wrap costs are tied to an
 *    order, so they reach that order's profit through the ledger. No orders, none.
 *
 * Every step goes through the Finance Actions with the clock at that Dhaka day, so ledger
 * entries carry the right `occurred_on` and both invariants hold. Finally the daily finance
 * rollup (`toymart:finance:rollup`'s service) rebuilds daily_financial_summaries for the
 * whole window, so the finance dashboard has history.
 *
 * Idempotent: one-off expenses are matched by their reference, templates by theirs; the
 * generator is idempotent per period; only still-pending rows are approved.
 */
class DemoFinanceSeeder extends Seeder
{
    /**
     * Monthly templates: reference → [category, amount, vendor, description, payment method]
     *
     * @var array<string, array{string, string, string, string, ExpensePaymentMethod}>
     */
    public const array RECURRING = [
        'RENT-MIRPUR-WH' => ['Rent', '45000.00', 'Mirpur warehouse landlord · মিরপুর গুদামের মালিক', 'Warehouse and office rent, Mirpur 10 · গুদাম ও অফিস ভাড়া', ExpensePaymentMethod::BankTransfer],
        'PAYROLL-MONTHLY' => ['Salaries', '118000.00', 'Staff payroll · কর্মীদের বেতন', 'Monthly salaries, 7 staff · মাসিক বেতন', ExpensePaymentMethod::BankTransfer],
        'ISP-MIRPUR-50MB' => ['Utilities', '2500.00', 'Mirpur Broadband Net · মিরপুর ব্রডব্যান্ড নেট', 'Office internet, 50 Mbps · অফিস ইন্টারনেট', ExpensePaymentMethod::Bkash],
    ];

    /**
     * One-off expenses: reference → [days ago (60-day window), category, amount, vendor,
     * description, flow, payment method, follow-up days ago, reason]. Flows:
     * `paid` (entered paid, approved), `pay_later` (approved, paid on the follow-up day),
     * `accrued` (approved, unpaid), `pending`, `rejected`, `void` (approved, voided on the follow-up day).
     *
     * @var array<string, array{int, string, string, string, string, string, ExpensePaymentMethod|null, int|null, string|null}>
     */
    public const array EXPENSES = [
        'MEMO-4471' => [55, 'Packaging', '6800.00', 'Lalbagh Carton House · লালবাগ কার্টন হাউস', 'Shipping cartons and bubble wrap · কার্টন ও বাবল র‍্যাপ', 'paid', ExpensePaymentMethod::Cash, null, null],
        'FB-INV-0926' => [50, 'Facebook ads', '15000.00', 'Facebook Ads', 'Puja gift campaign · পূজার উপহার ক্যাম্পেইন', 'paid', ExpensePaymentMethod::Card, null, null],
        'DESCO-0929' => [46, 'Utilities', '8400.00', 'DESCO electricity · ডেসকো বিদ্যুৎ', 'Electricity bill, September · বিদ্যুৎ বিল, সেপ্টেম্বর', 'pay_later', ExpensePaymentMethod::Bkash, 38, null],
        'GADS-1003' => [41, 'Google ads', '7500.00', 'Google Ads', 'Search ads, educational toys · সার্চ বিজ্ঞাপন', 'paid', ExpensePaymentMethod::Card, null, null],
        'MEMO-4529' => [36, 'Packaging', '4500.00', 'Lalbagh Carton House · লালবাগ কার্টন হাউস', 'Tape and poly mailers · টেপ ও পলি মেইলার', 'pay_later', ExpensePaymentMethod::Cash, 22, null],
        'MEMO-TEA-1010' => [33, 'Other', '2200.00', 'Mirpur 10 tea stall · চায়ের দোকান', 'Office tea and snacks · অফিসের চা-নাস্তা', 'rejected', ExpensePaymentMethod::Cash, 31, 'No memo attached — resubmit with the shop receipt · দোকানের রসিদসহ আবার জমা দিন'],
        'INF-VID-1014' => [29, 'Advertising', '12000.00', 'Kids unboxing video creator · আনবক্সিং ভিডিও', 'Unboxing video, LEGO Classic · আনবক্সিং ভিডিও', 'void', ExpensePaymentMethod::Bkash, 25, 'Duplicate — already booked under Facebook ads · ভুল করে দুবার এন্ট্রি'],
        'FB-INV-1020' => [24, 'Facebook ads', '18000.00', 'Facebook Ads', 'Winter sale campaign · শীতের সেল ক্যাম্পেইন', 'paid', ExpensePaymentMethod::Bkash, null, null],
        'VAN-HIRE-1027' => [17, 'Other', '3500.00', 'Pickup van hire · পিকআপ ভাড়া', 'Chawkbazar stock pickup run · চকবাজার থেকে মাল আনা', 'paid', ExpensePaymentMethod::Cash, null, null],
        'WASA-1031' => [13, 'Utilities', '3100.00', 'Dhaka WASA · ঢাকা ওয়াসা', 'Water bill, October (due) · পানির বিল, অক্টোবর', 'accrued', null, null, null],
        'MEMO-4610' => [8, 'Packaging', '5200.00', 'Lalbagh Carton House · লালবাগ কার্টন হাউস', 'Gift boxes for the winter sale · উপহারের বাক্স', 'pending', ExpensePaymentMethod::Cash, null, null],
        'FUEL-1108' => [5, 'Other', '1500.00', 'Generator fuel · জেনারেটরের জ্বালানি', 'Diesel during load-shedding · লোডশেডিংয়ে ডিজেল', 'pending', null, null, null],
        'GADS-1110' => [3, 'Google ads', '6000.00', 'Google Ads', 'Shopping ads test · শপিং বিজ্ঞাপন', 'pending', ExpensePaymentMethod::Card, null, null],
    ];

    /** Gift-wrap cost tied to an order, per order (at most ORDER_LINKED orders). */
    public const string ORDER_WRAP_AMOUNT = '120.00';

    public const int ORDER_LINKED = 2;

    private ?int $accountantId = null;

    private ?int $managerId = null;

    /** Today in Dhaka, read once before any step moves the clock. */
    private CarbonImmutable $today;

    public function run(): void
    {
        Carbon::setTestNow(null);
        CarbonImmutable::setTestNow(null);
        $this->today = DhakaDate::todayDate();

        $owner = User::query()->where('email', 'owner@toymart.test')->value('id');
        $this->accountantId = DemoStaffSeeder::user(Role::Accountant)->id ?? (is_numeric($owner) ? (int) $owner : null);
        $this->managerId = DemoStaffSeeder::user(Role::Manager)->id ?? (is_numeric($owner) ? (int) $owner : null);

        try {
            $this->recurring();
            $this->oneOffs();
            $this->orderLinked();
            $this->landedCosts();
        } finally {
            Carbon::setTestNow(null);
            CarbonImmutable::setTestNow(null);
        }

        app(DailySummaryRollup::class)->run($this->day(60)->toDateString(), $this->today->toDateString());
    }

    /** Templates in the first month of the window, then each later month's generated copies. */
    private function recurring(): void
    {
        foreach (self::RECURRING as $reference => [$category, $amount, $vendor, $description, $method]) {
            if ($this->exists($reference)) {
                continue;
            }
            $expense = $this->at($this->day(58), '10:00', fn (): Expense => $this->create($category, $amount, $vendor, $description, $reference, $method, recurring: true));
            $this->at($this->day(57), '15:00', fn (): Expense => app(ApproveExpense::class)->handle($expense, $this->managerId));
        }

        $templateMonth = $this->day(58)->startOfMonth();
        $thisMonth = $this->today->startOfMonth();
        for ($month = $templateMonth->addMonth(); $month->lessThanOrEqualTo($thisMonth); $month = $month->addMonth()) {
            $period = $month->format('Y-m');
            $this->at($month, '00:30', static fn (): int => app(GenerateRecurringExpenses::class)->handle($period));

            if ($month->equalTo($thisMonth)) {
                continue; // this month's copies wait for approval
            }
            $copies = Expense::query()->where('recurrence_period', $period)->whereNotNull('recurrence_parent_id')
                ->where('status', ExpenseStatus::Pending->value)->orderBy('id')->get();
            foreach ($copies as $copy) {
                $method = self::RECURRING[(string) $copy->reference][4] ?? ExpensePaymentMethod::BankTransfer;
                $this->at($month->addDays(2), '11:00', fn (): Expense => app(ApproveExpense::class)->handle($copy, $this->managerId));
                $this->at($month->addDays(4), '12:00', static fn (): Expense => app(MarkExpensePaid::class)->handle($copy, $method, $month->addDays(4)));
            }
        }
    }

    private function oneOffs(): void
    {
        foreach (self::EXPENSES as $reference => [$daysAgo, $category, $amount, $vendor, $description, $flow, $method, $followUp, $reason]) {
            if ($this->exists($reference)) {
                continue;
            }
            $day = $this->day($daysAgo);
            $paidAtEntry = in_array($flow, ['paid', 'pending', 'rejected', 'void'], true) ? $method : null;
            $expense = $this->at($day, '11:15', fn (): Expense => $this->create($category, $amount, $vendor, $description, $reference, $paidAtEntry));

            $later = $followUp === null ? $day->addDay() : $this->day($followUp);
            match ($flow) {
                'paid', 'accrued' => $this->at($day->addDay(), '16:00', fn (): Expense => app(ApproveExpense::class)->handle($expense, $this->managerId)),
                'pay_later' => $this->approveThenPay($expense, $day->addDay(), $later, $method),
                'rejected' => $this->at($later, '16:00', static fn (): Expense => app(RejectExpense::class)->handle($expense, (string) $reason)),
                'void' => $this->approveThenVoid($expense, $day->addDay(), $later, (string) $reason),
                default => $expense,
            };
        }
    }

    private function approveThenPay(Expense $expense, CarbonImmutable $approveOn, CarbonImmutable $paidOn, ?ExpensePaymentMethod $method): Expense
    {
        $method ??= ExpensePaymentMethod::Cash;
        $this->at($approveOn, '16:00', fn (): Expense => app(ApproveExpense::class)->handle($expense, $this->managerId));

        return $this->at($paidOn, '12:00', static fn (): Expense => app(MarkExpensePaid::class)->handle($expense, $method, $paidOn));
    }

    private function approveThenVoid(Expense $expense, CarbonImmutable $approveOn, CarbonImmutable $voidOn, string $reason): Expense
    {
        $this->at($approveOn, '16:00', fn (): Expense => app(ApproveExpense::class)->handle($expense, $this->managerId));

        return $this->at($voidOn, '17:30', fn (): Expense => app(VoidExpense::class)->handle($expense, $reason, $this->managerId));
    }

    /** Gift wrap charged to the first orders, on the order's own Dhaka day. Tolerates none. */
    private function orderLinked(): void
    {
        $orders = Order::query()->orderBy('id')->limit(self::ORDER_LINKED)->get(['id', 'number', 'created_at']);
        foreach ($orders as $order) {
            $reference = 'WRAP-'.$order->number;
            if ($this->exists($reference) || $order->created_at === null) {
                continue;
            }
            $day = DhakaDate::local($order->created_at)->startOfDay();
            if ($day->greaterThan($this->today)) {
                continue;
            }
            $expense = $this->at($day, '18:00', fn (): Expense => $this->create(
                'Packaging', self::ORDER_WRAP_AMOUNT, 'Lalbagh Carton House · লালবাগ কার্টন হাউস',
                'Gift wrap for order '.$order->number.' · উপহার মোড়ক', $reference, ExpensePaymentMethod::Cash, orderId: $order->id,
            ));
            $this->at($day, '20:00', fn (): Expense => app(ApproveExpense::class)->handle($expense, $this->managerId));
        }
    }

    /** Approve the capitalised freight/customs expenses the purchasing receipts raised, two days on. */
    private function landedCosts(): void
    {
        $pending = Expense::query()->where('status', ExpenseStatus::Pending->value)
            ->whereHas('category', static fn ($q) => $q->where('capitalised', true))
            ->orderBy('id')->get();
        foreach ($pending as $expense) {
            $on = CarbonImmutable::createFromFormat('!Y-m-d', $expense->expense_date->toDateString(), DhakaDate::zone())?->addDays(2);
            if ($on === null || $on->greaterThan($this->today)) {
                continue;
            }
            $this->at($on, '15:30', fn (): Expense => app(ApproveExpense::class)->handle($expense, $this->managerId));
        }
    }

    private function create(string $category, string $amount, string $vendor, string $description, string $reference, ?ExpensePaymentMethod $method, bool $recurring = false, ?int $orderId = null): Expense
    {
        $categoryId = ExpenseCategory::query()->where('name', $category)->where('is_active', true)->value('id');
        if (! is_numeric($categoryId)) {
            throw new RuntimeException("DemoFinanceSeeder needs expense category [{$category}] — run ExpenseCategoriesSeeder first.");
        }

        return app(CreateExpense::class)->handle(new ExpenseData(
            categoryId: (int) $categoryId,
            amount: Money::of($amount),
            expenseDate: DhakaDate::todayDate(),
            paymentMethod: $method,
            paidOn: $method === null ? null : DhakaDate::todayDate(),
            reference: $reference,
            vendor: $vendor,
            description: $description,
            isRecurring: $recurring,
            recurrence: $recurring ? ExpenseRecurrence::Monthly : null,
            orderId: $orderId,
            shipmentId: null,
        ), null, $this->accountantId);
    }

    private function exists(string $reference): bool
    {
        return Expense::query()->where('reference', $reference)->whereNull('recurrence_parent_id')->exists();
    }

    /** A Dhaka day `$daysAgo` days back on a 60-day window, scaled to `demo.history_days`; never today. */
    private function day(int $daysAgo): CarbonImmutable
    {
        return $this->today->subDays($this->scale($daysAgo));
    }

    private function scale(int $daysAgo): int
    {
        $history = max(1, (int) config('demo.history_days', 60));

        return max(1, (int) round($daysAgo * $history / 60));
    }

    /**
     * Run a step with the clock at that Dhaka day and time, then put the clock back.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function at(CarbonImmutable $day, string $time, callable $step): mixed
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $instant = $day->setTimezone(DhakaDate::zone())->setTime($hour, $minute)->utc();
        $previous = [Carbon::getTestNow(), CarbonImmutable::getTestNow()];

        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
        try {
            return $step();
        } finally {
            Carbon::setTestNow($previous[0]);
            CarbonImmutable::setTestNow($previous[1]);
        }
    }
}
