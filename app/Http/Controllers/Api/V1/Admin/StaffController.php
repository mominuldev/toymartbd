<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\StaffResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff management (P3-API-06). Read-only for now; create/update arrive with the
 * staff screens and go through FormRequests authorised by UserPolicy.
 */
final class StaffController extends ApiController
{
    public const int PER_PAGE = 25;

    /** `GET /staff` — cursor-paginated `{ data, links, meta }`, needs staff.view. */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $staff = User::query()
            ->with('roles')
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE);

        return StaffResource::collection($staff);
    }
}
