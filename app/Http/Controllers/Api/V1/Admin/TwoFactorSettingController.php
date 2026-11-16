<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Staff\Actions\UpdateTwoFactorRequirement;
use App\Domain\Staff\Services\TwoFactor;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Staff\UpdateTwoFactorSettingRequest;
use App\Http\Resources\Admin\TwoFactorSettingResource;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Staff & roles › Two-factor sign-in (owner request 2026-11-16): whether staff who can see money
 * must use two-factor. Off by default. Read with staff.view, change with staff.manage.
 */
final class TwoFactorSettingController extends ApiController
{
    /** `GET /staff/two-factor` */
    public function show(TwoFactor $twoFactor): TwoFactorSettingResource
    {
        $this->authorize(Permission::StaffView->value);

        return self::view($twoFactor);
    }

    /** `PUT /staff/two-factor` */
    public function update(UpdateTwoFactorSettingRequest $request, UpdateTwoFactorRequirement $update, TwoFactor $twoFactor): TwoFactorSettingResource
    {
        $actorId = $request->user()?->getAuthIdentifier();
        $update->handle($request->isRequired(), is_int($actorId) ? $actorId : null);

        return self::view($twoFactor);
    }

    private static function view(TwoFactor $twoFactor): TwoFactorSettingResource
    {
        $without = User::query()
            ->where('is_active', true)
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->filter(static fn (User $u): bool => ! $u->hasTwoFactor() && $twoFactor->seesMoney($u))
            ->count();

        return new TwoFactorSettingResource(['required' => TwoFactor::requiredByOwner(), 'staff_without' => $without]);
    }
}
