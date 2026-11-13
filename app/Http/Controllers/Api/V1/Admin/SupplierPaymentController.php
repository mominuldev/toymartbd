<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Purchasing\Actions\RecordSupplierPayment;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Purchasing\StoreSupplierPaymentRequest;
use App\Http\Resources\Admin\SupplierPaymentResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Supplier payments (PRD N-10). Immutable once recorded; each writes one
 * `supplier_payment` ledger entry (cash out, not revenue) through Finance.
 */
final class SupplierPaymentController extends ApiController
{
    public const int PER_PAGE = 25;

    /** `GET /suppliers/{supplier}/payments` — newest first, cursor-paginated; needs finance.view. No filters. */
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    public function index(Request $request, Supplier $supplier): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplierPayment::class);

        $unknown = array_diff(array_keys($request->query()), ['cursor']);
        if ($unknown !== []) {
            abort(400, 'Unknown query parameter: '.implode(', ', $unknown).'.');
        }

        $payments = SupplierPayment::query()
            ->with('purchase:id,number')
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return SupplierPaymentResource::collection($payments);
    }

    /**
     * `POST /suppliers/{supplier}/payments` — needs finance.record_supplier_payments and an
     * `Idempotency-Key`. 422 when `purchase_id` belongs to another supplier or is a draft/cancelled PO.
     */
    #[OpenApiResponse(403, 'Missing finance.record_supplier_payments (`forbidden`)')]
    public function store(StoreSupplierPaymentRequest $request, Supplier $supplier, RecordSupplierPayment $record): JsonResponse
    {
        $payment = $record->handle($supplier, $request->toData(), $request->user()?->id);

        return (new SupplierPaymentResource($payment->load('purchase:id,number')))->response()->setStatusCode(201);
    }
}
