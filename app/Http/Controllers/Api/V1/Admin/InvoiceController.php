<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Actions\IssuePurchaseInvoice;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Purchasing\Models\Purchase;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Invoicing\InvoicePdfRequest;
use App\Http\Resources\Admin\InvoiceListItemResource;
use App\Http\Resources\Admin\InvoiceResource;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Issued invoices (P8, PRD N-13): the list, one invoice, its reprint, and the supplier
 * invoice of a PO. An invoice is issued by its first print (orders: OrderInvoiceController;
 * POs: {@see self::purchase()}) and never changes — the reprint renders the snapshot.
 */
final class InvoiceController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /invoices` — newest first, cursor-paginated. Only the types the caller may see
     * (customer: orders.view; supplier: purchasing.view + catalog.view_cost).
     *
     * Filters: `filter[type]` (`sales`|`purchase`), `filter[kind]` (`original`|`supplementary`,
     * P8 batch 3), `filter[search]` (invoice number, order /
     * PO number or the customer / supplier name contains), `filter[issued_from]` and
     * `filter[issued_to]` (Dhaka dates, Y-m-d, inclusive). Sorts: `issued_at`, `-issued_at`
     * (default), `number`, `-number`. Anything else is a 400.
     */
    #[QueryParameter('filter[type]', 'Invoice type.', type: "'sales'|'purchase'")]
    #[QueryParameter('filter[kind]', 'Original invoices, or the supplementary invoices of orders edited upwards after issue.', type: "'original'|'supplementary'")]
    #[QueryParameter('filter[search]', 'Invoice number, order/PO number or customer/supplier name contains.', type: 'string')]
    #[QueryParameter('filter[issued_from]', 'Issued on or after this Dhaka date (Y-m-d).', type: 'string')]
    #[QueryParameter('filter[issued_to]', 'Issued on or before this Dhaka date (Y-m-d).', type: 'string')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'issued_at'|'-issued_at'|'number'|'-number'", default: '-issued_at')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request, InvoicePolicy $policy): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);
        /** @var User $user */
        $user = $request->user();
        $types = array_map(static fn (InvoiceType $t): string => $t->value, $policy->types($user));

        $sort = $request->query('sort', '-issued_at');
        $descending = ! is_string($sort) || str_starts_with($sort, '-');

        $invoices = QueryBuilder::for(Invoice::query()->with(['creditNotes', 'supplementaryInvoices', 'originalInvoice:id,number'])->whereIn('type', $types), $request)
            ->allowedFilters(
                AllowedFilter::callback('type', static function (Builder $query, mixed $value): void {
                    $type = is_string($value) ? InvoiceType::tryFrom($value) : null;
                    if ($type === null) {
                        throw new BadRequestHttpException('filter[type] must be one of: '.implode(', ', InvoiceType::values()).'.');
                    }
                    $query->where('type', $type->value);
                }),
                AllowedFilter::callback('kind', static function (Builder $query, mixed $value): void {
                    $kind = is_string($value) ? InvoiceKind::tryFrom($value) : null;
                    if ($kind === null) {
                        throw new BadRequestHttpException('filter[kind] must be one of: '.implode(', ', InvoiceKind::values()).'.');
                    }
                    $query->where('kind', $kind->value);
                }),
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = trim(is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value);
                    $like = '%'.addcslashes($term, '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('number', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhere('issued_to->name', 'like', $like));
                }),
                AllowedFilter::callback('issued_from', static function (Builder $query, mixed $value): void {
                    $query->where('issued_at', '>=', self::bounds('issued_from', $value)[0]);
                }),
                AllowedFilter::callback('issued_to', static function (Builder $query, mixed $value): void {
                    $query->where('issued_at', '<', self::bounds('issued_to', $value)[1]);
                }),
            )
            ->allowedSorts('issued_at', 'number')
            ->defaultSort('-issued_at')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return InvoiceListItemResource::collection($invoices);
    }

    /** `GET /invoices/{invoice}` — the snapshot: company, recipient (PII masked without customers.view_pii), lines, totals. */
    public function show(Invoice $invoice): InvoiceResource
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load(['creditNotes', 'supplementaryInvoices', 'originalInvoice:id,number']));
    }

    /** `GET /invoices/{invoice}/pdf` — reprint from the snapshot (`locale` optional). */
    #[OpenApiResponse(200, 'The invoice PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function pdf(InvoicePdfRequest $request, Invoice $invoice, OrderInvoicePdf $pdf): Response
    {
        $this->authorize('print', $invoice);
        $locale = $invoice->type === InvoiceType::Sales ? OrderInvoiceController::locale($invoice, $request->locale()) : ($request->locale() ?? 'en');

        return OrderInvoiceController::pdf(
            $pdf->render([['invoice' => $invoice, 'locale' => $locale]]),
            $invoice->number.'.pdf',
            ['X-Invoice-Numbers' => $invoice->number],
        );
    }

    /**
     * `GET /purchases/{purchase}/invoice` — the PO's supplier invoice as a PDF, issued
     * (`PINV-YY-NNNNNN`) on the first print. English unless `locale=bn`. A draft or
     * cancelled PO is a 409.
     */
    #[OpenApiResponse(200, 'The supplier invoice PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    #[OpenApiResponse(409, 'The PO is a draft or cancelled (`conflict`)')]
    public function purchase(InvoicePdfRequest $request, Purchase $purchase, IssuePurchaseInvoice $issue, OrderInvoicePdf $pdf): Response
    {
        $this->authorize('printPurchase', Invoice::class);
        $userId = $request->user()?->getAuthIdentifier();
        $invoice = $issue->handle($purchase, is_int($userId) ? $userId : null);

        return OrderInvoiceController::pdf(
            $pdf->render([['invoice' => $invoice, 'locale' => $request->locale() ?? 'en']]),
            $invoice->number.'.pdf',
            ['X-Invoice-Numbers' => $purchase->id.':'.$invoice->number],
        );
    }

    /** @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable} */
    private static function bounds(string $name, mixed $value): array
    {
        try {
            return DhakaDate::utcBounds(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException("filter[{$name}] must be a Y-m-d date.");
        }
    }
}
