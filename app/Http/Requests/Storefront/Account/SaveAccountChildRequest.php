<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Http\Requests\ChildRules;

/**
 * `POST /storefront/account/children` and `PATCH …/children/{child}` (P8 batch 3) — the signed-in
 * parent adds or edits one of their own children. PATCH replaces every field.
 */
final class SaveAccountChildRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ChildRules::rules();
    }

    /** @return array{name: string|null, month: int, day: int, year: int|null} */
    public function child(): array
    {
        return ChildRules::values($this);
    }
}
