<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in person's two-factor state. No secret, no code — only whether it is on,
 * whether their role requires it, and how many unused recovery codes remain.
 *
 * @property array{user: User, required: bool} $resource
 */
final class TwoFactorStatusResource extends ApiResource
{
    /**
     * @return array{enabled: bool, required: bool, confirmed_at: string|null, recovery_codes_left: int}
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];

        return [
            'enabled' => (bool) $user->hasTwoFactor(),
            'required' => (bool) $this->resource['required'],
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_left' => (int) ($user->hasTwoFactor() ? count($user->two_factor_recovery_codes ?? []) : 0),
        ];
    }
}
