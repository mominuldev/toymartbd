<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

/**
 * `PUT /storefront/account/consents` — the signed-in customer opts in to (or out of) birthday
 * messages (P8). Recorded with source `storefront`; a "no" withdraws, it never deletes.
 */
final class UpdateConsentRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** True: send me a message on my birthday. False: stop. */
            'birthday' => ['required', 'boolean'],
        ];
    }

    public function birthday(): bool
    {
        return $this->boolean('birthday');
    }
}
