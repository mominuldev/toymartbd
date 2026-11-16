<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Actions\SaveCapitalHolder;
use App\Domain\Finance\Models\CapitalHolder;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Finance\Capital\CapitalHolderRequest;
use App\Http\Requests\Admin\Finance\FinanceFilters;
use App\Http\Resources\Admin\CapitalHolderResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Capital holders (P10): the owner and partners. A short list, returned whole (active first,
 * then by name). Never deleted — deactivate with `PATCH {is_active: false}`.
 */
final class CapitalHolderController extends ApiController
{
    /** `GET /finance/capital/holders` — every holder with its entry count; needs finance.view. */
    #[QueryParameter('filter[active]', 'Only active (true) or only inactive (false) holders.', type: 'boolean')]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CapitalHolder::class);
        $f = FinanceFilters::from($request, ['active'], ['name'], 'name');
        if ($request->has('sort')) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('The holder list takes no sort.');
        }
        $active = $f->bool('active');

        return CapitalHolderResource::collection(
            CapitalHolder::query()->with('user')->withCount('entries')
                ->when($active !== null, static fn ($q) => $q->where('is_active', $active))
                ->orderByDesc('is_active')->orderBy('name')
                ->get(),
        );
    }

    /**
     * `GET /finance/capital/staff` — active staff accounts a holder may be linked to ("has a staff
     * login"): id and name only, never an email or phone, and not already linked to a holder.
     * Needs finance.manage_capital (it serves the add/edit holder form).
     *
     * @return array{data: list<array{id: int, name: string}>}
     */
    public function staff(): array
    {
        $this->authorize('create', CapitalHolder::class);
        $linked = CapitalHolder::query()->whereNotNull('user_id')->pluck('user_id')->all();

        return ['data' => User::query()->where('is_active', true)->whereNotIn('id', $linked)->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])->values()->all()];
    }

    /** `POST /finance/capital/holders` — needs finance.manage_capital. */
    public function store(CapitalHolderRequest $request, SaveCapitalHolder $save): JsonResponse
    {
        return (new CapitalHolderResource($save->handle(null, $request->holderAttributes(), $request->user()?->id)->loadCount('entries')))
            ->response()->setStatusCode(201);
    }

    /** `PATCH /finance/capital/holders/{holder}` — needs finance.manage_capital. */
    public function update(CapitalHolderRequest $request, CapitalHolder $holder, SaveCapitalHolder $save): CapitalHolderResource
    {
        return new CapitalHolderResource($save->handle($holder, $request->holderAttributes(), $request->user()?->id)->loadCount('entries'));
    }
}
