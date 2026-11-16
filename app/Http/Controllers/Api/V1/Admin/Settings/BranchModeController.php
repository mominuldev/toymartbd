<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Inventory\Actions\UpdateBranchMode;
use App\Domain\Inventory\Exceptions\BranchModeConflict;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\BranchMode;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Http\Requests\Admin\StoreSettings\UpdateBranchModeRequest;
use App\Http\Resources\Admin\StoreSettings\BranchModeResource;
use App\Models\Enums\Permission;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store settings › Branches & warehouses › Branch setup (owner request 2026-11-16): run the
 * store as a single shop or with multiple branches ({@see BranchMode}). Read with settings.view,
 * save with settings.update.
 */
final class BranchModeController extends ApiController
{
    /** `GET /settings/branch-mode` */
    public function show(): BranchModeResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view();
    }

    /** `PUT /settings/branch-mode` */
    #[OpenApiResponse(409, 'Other branches are still active (`branch-mode-blocked`, with a `blockers` list of {id, name, name_bn, units})')]
    public function update(UpdateBranchModeRequest $request, UpdateBranchMode $update): BranchModeResource|JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();

        try {
            $update->handle($request->multiBranch(), is_int($userId) ? $userId : null);
        } catch (BranchModeConflict $e) {
            return self::problem($request, $e);
        }

        return self::view();
    }

    /** The 409 for a branch-mode refusal; shared with the locations controller. */
    public static function problem(Request $request, BranchModeConflict $e): JsonResponse
    {
        $type = $e->blocking ? ProblemType::BranchModeBlocked : ProblemType::SingleBranchMode;

        return (new ProblemDetails(
            $type,
            $type->status(),
            $e->getMessage(),
            extensions: $e->blocking ? ['blockers' => BranchModeResource::blockers($e->blockers)] : [],
        ))->toResponse($request);
    }

    private static function view(): BranchModeResource
    {
        $active = StockLocation::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get();
        $default = StockLocation::query()->where('is_default', true)->orderBy('id')->first();

        return new BranchModeResource([
            'multi_branch' => BranchMode::isMulti(),
            'saved' => BranchMode::saved() !== null,
            'active_locations' => $active->count(),
            'default_location' => $default,
            'blockers' => $active->filter(static fn (StockLocation $l): bool => ! $l->is_default)->values()->all(),
        ]);
    }
}
