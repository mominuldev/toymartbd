<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One of a customer's children (P8 batch 3) — for birthday reminders with a gift offer.
 * Children's data, minimised: an optional first name / nickname, the birthday as month + day,
 * and an optional birth year (only to show an age, as the approved design does). Never logged
 * (rule 20); shown to staff only with customers.view_pii; DELETED when the parent removes it.
 * Written only by {@see \App\Domain\Customer\Actions\SaveCustomerChild} and
 * {@see \App\Domain\Customer\Actions\RemoveCustomerChild}.
 *
 * @property int $id
 * @property int $customer_id
 * @property string|null $name
 * @property int $birth_month
 * @property int $birth_day
 * @property int|null $birth_year
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CustomerChild extends Model
{
    /** Owner decision 2026-11-13: a sensible cap, not a business rule — raise it here. */
    public const int MAX_PER_CUSTOMER = 6;

    public const int NAME_MAX = 40;

    protected $table = 'customer_children';

    /** @var list<string> */
    protected $fillable = ['customer_id', 'name', 'birth_month', 'birth_day', 'birth_year'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'birth_month' => 'integer',
            'birth_day' => 'integer',
            'birth_year' => 'integer',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * Is month/day (and year, when given) a real calendar date that is not in the future?
     * 29 February is allowed without a year; with a year it must be a leap year.
     */
    public static function validBirthday(int $month, int $day, ?int $year, CarbonImmutable $today): bool
    {
        if (! checkdate($month, $day, $year ?? 2000)) {
            return false;
        }
        if ($year === null) {
            return true;
        }

        return $year >= $today->year - 25 && sprintf('%04d-%02d-%02d', $year, $month, $day) <= $today->toDateString();
    }

    /**
     * The (Dhaka) date this child's birthday is celebrated in a given year: 29 February falls
     * on 28 February in a non-leap year.
     */
    public function birthdayIn(int $year): CarbonImmutable
    {
        $day = $this->birth_month === 2 && $this->birth_day === 29 && ! checkdate(2, 29, $year) ? 28 : $this->birth_day;

        return CarbonImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $this->birth_month, $day), DhakaDate::zone())
            ?? throw new LogicException('Unreachable: a stored birthday is a real date.');
    }

    /** The next celebration on or after a Dhaka calendar date. */
    public function nextBirthday(CarbonImmutable $today): CarbonImmutable
    {
        $thisYear = $this->birthdayIn($today->year);

        return $thisYear->toDateString() < $today->toDateString() ? $this->birthdayIn($today->year + 1) : $thisYear;
    }

    /** Whole years on a (Dhaka) date, or null without a birth year. */
    public function ageOn(CarbonImmutable $today): ?int
    {
        if ($this->birth_year === null) {
            return null;
        }
        $age = $today->year - $this->birth_year;
        $birthday = sprintf('%02d-%02d', $this->birth_month, $this->birth_day);

        return max(0, $today->format('m-d') < $birthday ? $age - 1 : $age);
    }
}
