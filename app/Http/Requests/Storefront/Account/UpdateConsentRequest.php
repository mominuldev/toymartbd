<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

/**
 * `PUT /storefront/account/consents` — the signed-in customer opts in to (or out of) birthday
 * reminders with offers — a message before each child's birthday, with a gift code when the shop
 * runs one (P8 batch 3). Recorded with source `storefront`; a "no" withdraws, it never deletes.
 */
final class UpdateConsentRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** True: remind me before my children's birthdays, with offers. False: stop. */
            'birthday' => ['required', 'boolean'],
        ];
    }

    public function birthday(): bool
    {
        return $this->boolean('birthday');
    }
}
