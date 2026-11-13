<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Customers;

use App\Domain\Customer\Models\CustomerChild;
use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One child (P8 batch 3), for staff with customers.view_pii only — the profile omits the list
 * otherwise. `age` and `next_birthday` are the server's, on the Dhaka date (29 February →
 * 28 February in a non-leap year).
 *
 * @property CustomerChild $resource
 */
#[SchemaName('CustomerChild')]
final class CustomerChildResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::present($this->resource);
    }

    /** @return array{id: int, name: string|null, birth_month: int, birth_day: int, birth_year: int|null, age: int|null, next_birthday: string} */
    public static function present(CustomerChild $child): array
    {
        $today = DhakaDate::todayDate();

        return [
            'id' => $child->id,
            /** First name or nickname, if the parent gave one. */
            'name' => $child->name,
            /** 1–12. */
            'birth_month' => $child->birth_month,
            /** 1–31. */
            'birth_day' => $child->birth_day,
            /** Optional. */
            'birth_year' => $child->birth_year,
            /** Whole years today (Dhaka); null without a birth year. */
            'age' => $child->ageOn($today),
            /** Y-m-d (Dhaka): the next day the birthday is celebrated, today included. */
            'next_birthday' => $child->nextBirthday($today)->toDateString(),
        ];
    }
}
