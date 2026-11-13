<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Contracts\NumberSeries;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free document numbers per series and Dhaka year: `INV-26-000001`, `PINV-26-000001`,
 * `CN-26-000001` (credit notes, P8 batch 2) (six digits, like `SHP-26-000001`; it simply grows past 999,999). Western digits (rule 36).
 *
 * {@see self::lock()} takes the counter row `FOR UPDATE` inside the caller's transaction
 * (IssueSalesInvoice / IssuePurchaseInvoice / the credit-note Actions) and {@see self::take()} advances it in the
 * same transaction as the invoice insert: concurrent issues serialise on the row, and a
 * rolled-back issue returns its number — so the series never has a gap or a duplicate.
 */
final readonly class InvoiceNumberGenerator
{
    /**
     * Creates this year's counter row if missing — call BEFORE the transaction. Inserting it
     * inside the locking transaction lets two first issues of a year deadlock (INSERT IGNORE's
     * shared lock, then FOR UPDATE — caught by InvoiceNumberRaceTest on MySQL).
     */
    public function prepare(NumberSeries $type, ?CarbonImmutable $at = null): void
    {
        $year = (int) DhakaDate::local($at ?? CarbonImmutable::now())->format('Y');
        DB::table('invoice_number_sequences')->insertOrIgnore(['series' => $type->series(), 'year' => $year, 'last_value' => 0]);
    }

    /**
     * Locks the counter and returns the year and the value the NEXT invoice would take.
     *
     * @return array{year: int, next: int}
     */
    public function lock(NumberSeries $type, ?CarbonImmutable $at = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Invoice numbers are allocated inside a transaction only.');
        }

        $year = (int) DhakaDate::local($at ?? CarbonImmutable::now())->format('Y');
        $locked = fn (): mixed => DB::table('invoice_number_sequences')
            ->where('series', $type->series())->where('year', $year)
            ->lockForUpdate()->value('last_value');

        // prepare() created the row before the transaction. Never INSERT IGNORE it here
        // unconditionally: on an existing row that takes a shared lock, and two issuers that
        // both hold it deadlock upgrading to FOR UPDATE (InvoiceNumberRaceTest on MySQL).
        // Only a missing row (prepare() skipped, or the Dhaka year turned between the two
        // calls) takes the insert path.
        $last = $locked();
        if ($last === null) {
            DB::table('invoice_number_sequences')->insertOrIgnore(['series' => $type->series(), 'year' => $year, 'last_value' => 0]);
            $last = $locked();
        }

        return ['year' => $year, 'next' => (int) $last + 1];
    }

    /** Advances the locked counter to `$sequence` (the value {@see self::lock()} returned). */
    public function take(NumberSeries $type, int $year, int $sequence): string
    {
        DB::table('invoice_number_sequences')
            ->where('series', $type->series())->where('year', $year)
            ->update(['last_value' => $sequence]);

        return self::format($type, $year, $sequence);
    }

    public static function format(NumberSeries $type, int $year, int $sequence): string
    {
        return sprintf('%s-%02d-%06d', $type->series(), $year % 100, $sequence);
    }
}
