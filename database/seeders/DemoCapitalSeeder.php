<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Finance\Actions\RecordCapitalEntry;
use App\Domain\Finance\Actions\ReverseCapitalEntry;
use App\Domain\Finance\Enums\CapitalEntryKind;
use App\Domain\Finance\Enums\CapitalHolderRole;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Models\CapitalEntry;
use App\Domain\Finance\Models\CapitalHolder;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo owner capital (P10; local/testing only, from DemoDataSeeder before DemoFinanceSeeder so the
 * daily rollup includes it): the owner — linked to the `owner@toymart.test` login — and two
 * partners; their investments and drawings across the demo window, and one drawing entered twice
 * and reversed, so the Capital page shows every state (money in, money out, reversal, reversed).
 * Every entry goes through RecordCapitalEntry / ReverseCapitalEntry with the clock at that Dhaka
 * day, so the ledger rows are real (cash only, never P&L) and the invariants hold.
 *
 * Idempotent: skipped when any capital entry exists.
 */
class DemoCapitalSeeder extends Seeder
{
    public function run(RecordCapitalEntry $record, ReverseCapitalEntry $reverse): void
    {
        if (CapitalEntry::query()->exists()) {
            return;
        }

        $owner = User::query()->where('email', 'owner@toymart.test')->first();
        $accountant = DemoStaffSeeder::user(Role::Accountant);
        $by = $accountant->id ?? $owner?->id;

        $mominul = CapitalHolder::query()->firstOrCreate(['name' => 'Mominul Islam'], ['name_bn' => 'মমিনুল ইসলাম', 'role' => CapitalHolderRole::Owner, 'user_id' => $owner?->id]);
        $farhana = CapitalHolder::query()->firstOrCreate(['name' => 'Farhana Akter'], ['name_bn' => 'ফারহানা আক্তার', 'role' => CapitalHolderRole::Partner]);
        $tanvir = CapitalHolder::query()->firstOrCreate(['name' => 'Tanvir Hasan'], ['name_bn' => 'তানভীর হাসান', 'role' => CapitalHolderRole::Partner]);

        $in = CapitalEntryKind::Contribution;
        $out = CapitalEntryKind::Drawing;
        // [days ago, holder, kind, amount, method, reference, note]
        $rows = [
            [58, $mominul, $in, '500000.00', ExpensePaymentMethod::BankTransfer, 'DBBL-TRF-100245', 'Opening capital'],
            [55, $farhana, $in, '150000.00', ExpensePaymentMethod::BankTransfer, 'EBL-TRF-771203', 'Partner capital — first instalment'],
            [45, $tanvir, $in, '50000.00', ExpensePaymentMethod::Cash, null, 'First instalment'],
            [40, $mominul, $out, '25000.00', ExpensePaymentMethod::Cash, null, 'Personal — entered twice'],
            [30, $farhana, $in, '100000.00', ExpensePaymentMethod::Bkash, '8HJ2K9QP1M', 'Partner capital — second instalment'],
            [28, $mominul, $out, '40000.00', ExpensePaymentMethod::BankTransfer, 'DBBL-CHQ-004498', 'Monthly drawing'],
            [14, $tanvir, $in, '50000.00', ExpensePaymentMethod::Cash, null, 'Second instalment'],
            [6, $farhana, $out, '30000.00', ExpensePaymentMethod::BankTransfer, 'DBBL-CHQ-004512', 'Monthly drawing'],
            [3, $mominul, $in, '200000.00', ExpensePaymentMethod::Bkash, '9KQ7H2LM4P', 'Stock for the winter season'],
        ];

        $today = DhakaDate::todayDate();
        $twice = null;
        foreach ($rows as [$daysAgo, $holder, $kind, $amount, $method, $reference, $note]) {
            $day = $today->subDays($daysAgo);
            $entry = $this->at($day, '11:30', static fn (): CapitalEntry => $record->handle($holder, $kind, Money::of($amount), $day, $method, $reference, $note, $by));
            if ($note === 'Personal — entered twice') {
                $twice = $entry;
            }
        }

        if ($twice !== null) {
            $this->at($today->subDays(39), '10:00', static fn (): CapitalEntry => $reverse->handle($twice, 'Entered twice by mistake', $owner->id ?? $by));
        }
    }

    /**
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
