<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

/** An account read or a body-less write (sign out, delete, set default). */
final class AccountReadRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
