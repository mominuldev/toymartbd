<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Customer\Models\CustomerChild;
use App\Http\Resources\Admin\Customers\CustomerChildResource;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One of the signed-in parent's children (P8 batch 3): the "My kids" card. The same fields as
 * staff see; `age` and `next_birthday` are computed on the Dhaka date.
 *
 * @property CustomerChild $resource
 */
#[SchemaName('StorefrontAccountChild')]
final class AccountChildResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = CustomerChildResource::present($this->resource);

        return [
            'id' => $c['id'],
            /** First name or nickname, if given. */
            'name' => $c['name'],
            /** 1–12. */
            'birth_month' => $c['birth_month'],
            /** 1–31. */
            'birth_day' => $c['birth_day'],
            /** Optional. */
            'birth_year' => $c['birth_year'],
            /** Whole years today (Dhaka); null without a birth year. */
            'age' => $c['age'],
            /** Y-m-d (Dhaka): the next day the birthday is celebrated, today included. */
            'next_birthday' => $c['next_birthday'],
        ];
    }
}
