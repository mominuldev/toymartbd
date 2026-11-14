<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * `POST /auth/two-factor-challenge` — exactly one of `{ code }` (6 digits from the
 * authenticator app) or `{ recovery_code }`. No user yet: the session holds who is signing in.
 */
final class TwoFactorChallengeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
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
