<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Domain\Staff\Actions\BeginTwoFactorSetup;
use App\Domain\Staff\Actions\ConfirmTwoFactor;
use App\Domain\Staff\Actions\DisableTwoFactor;
use App\Domain\Staff\Actions\RegenerateRecoveryCodes;
use App\Domain\Staff\Services\TwoFactor;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Admin\Auth\TwoFactorCodeRequest;
use App\Http\Resources\Admin\RecoveryCodesResource;
use App\Http\Resources\Admin\TwoFactorSetupResource;
use App\Http\Resources\Admin\TwoFactorStatusResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The signed-in staff member's own two-factor sign-in (owner decision 2026-11-14). Any
 * active staff member may use it; it is REQUIRED for anyone who can see money, and these
 * endpoints stay reachable before enrolment (EnsureTwoFactorEnrolled::ALLOWED). Secrets and
 * recovery codes are returned only at the moment they are created, never again.
 */
final class TwoFactorController extends ApiController
{
    /** `GET /auth/two-factor` — on/off, whether it is required, recovery codes left. */
    public function show(Request $request, TwoFactor $twoFactor): TwoFactorStatusResource
    {
        $user = self::user($request);

        return new TwoFactorStatusResource(['user' => $user, 'required' => $twoFactor->requiredFor($user)]);
    }

    /** `POST /auth/two-factor` — start setup: a new secret as a QR code and a setup key. */
    #[OpenApiResponse(409, 'Two-factor is already on (`conflict`)')]
    public function store(Request $request, BeginTwoFactorSetup $begin, TwoFactor $twoFactor): TwoFactorSetupResource
    {
        $user = self::user($request);
        $secret = $begin->handle($user);
        $uri = $twoFactor->otpauthUri($user, $secret);

        return new TwoFactorSetupResource(['secret' => $secret, 'uri' => $uri, 'svg' => $twoFactor->qrSvg($uri)]);
    }

    /** `POST /auth/two-factor/confirm` — `{ code }`; turns it on and returns the recovery codes once. */
    public function confirm(TwoFactorCodeRequest $request, ConfirmTwoFactor $confirm): RecoveryCodesResource
    {
        return new RecoveryCodesResource($confirm->handle(self::user($request), $request->code()));
    }

    /** `POST /auth/two-factor/recovery-codes` — `{ code }`; new codes, the old ones stop working. */
    public function recoveryCodes(TwoFactorCodeRequest $request, RegenerateRecoveryCodes $regenerate): RecoveryCodesResource
    {
        return new RecoveryCodesResource($regenerate->handle(self::user($request), $request->code()));
    }

    /** `DELETE /auth/two-factor` — `{ code }` or `{ recovery_code }`; refused (409) when required. */
    #[OpenApiResponse(409, 'Your role can see money, so two-factor must stay on (`conflict`)')]
    public function destroy(DisableTwoFactorRequest $request, DisableTwoFactor $disable): Response
    {
        $disable->handle(self::user($request), $request->code(), $request->recoveryCode());

        return response()->noContent();
    }

    private static function user(Request $request): User
    {
        /** @var User $user guaranteed by auth:sanctum + EnsureActiveStaffSession */
        $user = $request->user();

        // The Actions write through a locked copy of the row; read the state they left.
        return $user->refresh();
    }
}
