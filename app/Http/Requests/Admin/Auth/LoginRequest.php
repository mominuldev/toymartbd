<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * `POST /api/v1/admin/auth/login` — `{ email, password, remember? }`.
 */
final class LoginRequest extends ApiFormRequest
{
    /** Anyone may attempt to sign in; the `login` rate limiter bounds the attempts. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }
}
