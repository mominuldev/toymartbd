<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Staff\Actions\ChangeOwnPassword;
use App\Domain\Staff\Actions\UpdateOwnAvatar;
use App\Domain\Staff\Actions\UpdateOwnProfile;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Auth\UpdateOwnAvatarRequest;
use App\Http\Requests\Admin\Auth\UpdateOwnPasswordRequest;
use App\Http\Requests\Admin\Auth\UpdateOwnProfileRequest;
use App\Http\Resources\Admin\AuthUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * My account (Figma B · 40 `547:83811`): the signed-in staff member's own name, sign-in email
 * and password. Anyone signed in may use it for themselves; other people's accounts are
 * changed only through Staff & roles (staff.manage).
 */
final class AccountController extends ApiController
{
    /** `PUT /auth/profile` — returns the refreshed `/auth/me` body. */
    public function updateProfile(UpdateOwnProfileRequest $request, UpdateOwnProfile $update): AuthUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AuthUserResource($update->handle($user, $request->name(), $request->email()));
    }

    /** `PUT /auth/password` — 204. Other browser sessions are signed out; this one stays. */
    public function updatePassword(UpdateOwnPasswordRequest $request, ChangeOwnPassword $change): Response
    {
        /** @var User $user */
        $user = $request->user();
        $change->handle($user, $request->password());

        return response()->noContent();
    }

    /** `POST /auth/avatar` — your profile photo, uploaded or picked from the media library; returns the refreshed `/auth/me` body. */
    public function updateAvatar(UpdateOwnAvatarRequest $request, UpdateOwnAvatar $update): AuthUserResource
    {
        /** @var User $user */
        $user = $request->user();

        $path = $request->avatarPath();
        $updated = $path !== null
            ? $update->handle($user, $path)
            : $update->fromMedia($user, Media::query()->findOrFail($request->mediaId()));

        return new AuthUserResource($updated);
    }

    /** `DELETE /auth/avatar` — back to initials; returns the refreshed `/auth/me` body. */
    public function destroyAvatar(Request $request, UpdateOwnAvatar $update): AuthUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AuthUserResource($update->remove($user));
    }
}
