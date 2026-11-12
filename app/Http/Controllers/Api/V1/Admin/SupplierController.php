<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Purchasing\Actions\ArchiveSupplier;
use App\Domain\Purchasing\Actions\CreateSupplier;
use App\Domain\Purchasing\Actions\UpdateSupplier;
use App\Domain\Purchasing\Enums\SupplierStatus;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Services\SupplierBalances;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Purchasing\StoreSupplierRequest;
use App\Http\Requests\Admin\Purchasing\UpdateSupplierRequest;
use App\Http\Resources\Admin\SupplierResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Suppliers (P5 purchasing, PRD I-03). Archive, never delete. The payable balance is
 * included for staff with finance.view.
 */
final class SupplierController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /suppliers` — cursor-paginated, needs purchasing.view.
     *
     * Filters: `filter[search]` (name, contact person or phone contains), `filter[status]` = `active|archived`.
     * Sorts: `name`, `-name`, `created_at`, `-created_at` (default `name`). Anything else is a 400.
     */
    #[QueryParameter('filter[search]', 'Case-insensitive "contains" on name, contact person or phone.', type: 'string')]
    #[QueryParameter('filter[status]', 'Only active or only archived suppliers.', type: "'active'|'archived'")]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'name'|'-name'|'created_at'|'-created_at'", default: 'name')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request, SupplierBalances $balances): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Supplier::class);

        $sort = $request->query('sort');
        $descending = is_string($sort) && str_starts_with($sort, '-');

        $suppliers = QueryBuilder::for(Supplier::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $like = '%'.addcslashes($term, '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('name', 'like', $like)
                        ->orWhere('contact_person', 'like', $like)
                        ->orWhere('phone', 'like', $like));
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $status = is_string($value) ? SupplierStatus::tryFrom($value) : null;
                    if ($status === null) {
                        throw new BadRequestHttpException('filter[status] must be one of: active, archived.');
                    }
                    $query->where('status', $status->value);
                }),
            )
            ->allowedSorts('name', 'created_at')
            ->defaultSort('name')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        $withBalance = $request->user()?->can('viewBalance', Supplier::class) ?? false;
        $sums = $withBalance ? $balances->forMany($suppliers->items()) : [];

        return SupplierResource::collection($suppliers->through(
            static fn (Supplier $s): SupplierResource => (new SupplierResource($s))->withBalance($sums[$s->id] ?? null),
        ));
    }

    /** `POST /suppliers` — needs purchasing.manage_suppliers. */
    public function store(StoreSupplierRequest $request, CreateSupplier $create, SupplierBalances $balances): JsonResponse
    {
        return self::resource($request, $create->handle($request->toData()), $balances)->response()->setStatusCode(201);
    }

    /** `GET /suppliers/{supplier}` — archived included; the balance needs finance.view. */
    public function show(Request $request, Supplier $supplier, SupplierBalances $balances): SupplierResource
    {
        $this->authorize('view', $supplier);

        return self::resource($request, $supplier, $balances);
    }

    /** `PATCH /suppliers/{supplier}` — needs purchasing.manage_suppliers. */
    public function update(UpdateSupplierRequest $request, Supplier $supplier, UpdateSupplier $update, SupplierBalances $balances): SupplierResource
    {
        return self::resource($request, $update->handle($supplier, $request->changes()), $balances);
    }

    /** `DELETE /suppliers/{supplier}` — archives (idempotent), needs purchasing.manage_suppliers. */
    public function destroy(Supplier $supplier, ArchiveSupplier $archive): Response
    {
        $this->authorize('delete', $supplier);

        $archive->handle($supplier);

        return response()->noContent();
    }

    private static function resource(Request $request, Supplier $supplier, SupplierBalances $balances): SupplierResource
    {
        $withBalance = $request->user()?->can('viewBalance', $supplier) ?? false;

        return (new SupplierResource($supplier))->withBalance($withBalance ? $balances->for($supplier) : null);
    }
}
