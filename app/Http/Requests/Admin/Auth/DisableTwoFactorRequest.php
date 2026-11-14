<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\ApiFormRequest;

/** `DELETE /auth/two-factor` — `{ code }` or `{ recovery_code }` proving the person holds the factor. */
final class DisableTwoFactorRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'required_without:recovery_code', 'prohibits:recovery_code', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:32'],
        ];
    }

    public function code(): ?string
    {
        return $this->filled('code') ? $this->string('code')->toString() : null;
    }

    public function recoveryCode(): ?string
    {
        return $this->filled('recovery_code') ? $this->string('recovery_code')->toString() : null;
    }
}
