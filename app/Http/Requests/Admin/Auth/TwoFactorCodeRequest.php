<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\ApiFormRequest;

/** `{ code }` — the 6-digit code from the authenticator app; for the signed-in staff member. */
final class TwoFactorCodeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }
}
