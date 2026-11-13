<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use Illuminate\Validation\Rule;

/** `PATCH /storefront/account` — the customer's own name, email, language and date of birth. */
final class UpdateProfileRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:191'],
            /** @var 'en'|'bn' */
            'preferred_language' => ['sometimes', 'required', 'string', Rule::in(['en', 'bn'])],
            /** Y-m-d, in the past; null clears it. Read only by birthday messages, and only with their consent. */
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before:today'],
        ];
    }

    /** @return array{name?: string, email?: string|null, preferred_language?: string, date_of_birth?: string|null} */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->trim()->toString();
        }
        if ($this->exists('email')) {
            $changes['email'] = $this->filled('email') ? $this->string('email')->trim()->lower()->toString() : null;
        }
        if ($this->has('preferred_language')) {
            $changes['preferred_language'] = $this->string('preferred_language')->toString();
        }
        if ($this->exists('date_of_birth')) {
            $changes['date_of_birth'] = $this->filled('date_of_birth') ? $this->string('date_of_birth')->toString() : null;
        }

        return $changes;
    }
}
