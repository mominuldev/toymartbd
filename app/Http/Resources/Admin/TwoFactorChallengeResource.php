<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * The 202 answer to `POST /auth/login` when the account has two-factor on: the password was
 * right, and the sign-in completes with `POST /auth/two-factor-challenge`.
 *
 * @property bool $resource
 */
final class TwoFactorChallengeResource extends ApiResource
{
    /** @return array{two_factor: bool} */
    public function toArray(Request $request): array
    {
        return ['two_factor' => true];
    }
}
