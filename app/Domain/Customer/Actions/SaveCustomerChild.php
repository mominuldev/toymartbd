<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerChild;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add or edit one of a customer's children (P8 batch 3) — by the parent on the storefront or by
 * staff with customers.update + customers.view_pii. The name is optional (a nickname is fine);
 * the birth year is optional (only to show an age). At most
 * {@see CustomerChild::MAX_PER_CUSTOMER}: the customer row is locked so two adds at once cannot
 * pass the cap. Nothing here is logged (children's data, rule 20).
 */
final readonly class SaveCustomerChild
{
    public function handle(Customer $customer, ?CustomerChild $child, ?string $name, int $month, int $day, ?int $year): CustomerChild
    {
        if (! CustomerChild::validBirthday($month, $day, $year, DhakaDate::todayDate())) {
            throw ValidationException::withMessages([$year !== null && checkdate($month, $day, $year) ? 'birth_year' : 'birth_day' => __('account.child.birthday_invalid')]);
        }
        $name = $name === null ? null : (trim($name) === '' ? null : mb_substr(trim($name), 0, CustomerChild::NAME_MAX));

        return DB::transaction(static function () use ($customer, $child, $name, $month, $day, $year): CustomerChild {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            if ($child === null) {
                if (CustomerChild::query()->where('customer_id', $customer->id)->count() >= CustomerChild::MAX_PER_CUSTOMER) {
                    throw ValidationException::withMessages(['children' => __('account.child.limit', ['max' => CustomerChild::MAX_PER_CUSTOMER])]);
                }
                $child = new CustomerChild(['customer_id' => $customer->id]);
            }

            $child->fill(['name' => $name, 'birth_month' => $month, 'birth_day' => $day, 'birth_year' => $year]);
            $child->save();

            return $child;
        });
    }
}
