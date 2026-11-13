<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Models\CreditNote;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Invoicing\InvoicePdfRequest;
use App\Http\Resources\Admin\CreditNoteListItemResource;
use App\Http\Resources\Admin\CreditNoteResource;
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
 * Credit notes (P8 batch 2): issued automatically when an invoiced order is cancelled or a
 * refund is paid on one (Invoicing listens to Ordering's events); here they are only listed,
 * read and reprinted. Immutable — there is no write endpoint.
 */
final class CreditNoteController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /credit-notes` — newest first, cursor-paginated (orders.view).
     *
     * Filters: `filter[invoice]` (invoice id), `filter[order]` (order id), `filter[reason]`
     * (`order_cancelled`|`refund`), `filter[search]` (credit-note number, invoice number,
     * order / RMA reference or customer name contains), `filter[issued_from]` /
     * `filter[issued_to]` (Dhaka dates, Y-m-d, inclusive). Sorts: `issued_at`, `-issued_at`
     * (default), `number`, `-number`, `total`, `-total`. Anything else is a 400.
     */
    #[QueryParameter('filter[invoice]', 'Credit notes of this invoice id.', type: 'integer')]
    #[QueryParameter('filter[order]', 'Credit notes of this order id.', type: 'integer')]
    #[QueryParameter('filter[reason]', 'Why it was issued.', type: "'order_cancelled'|'refund'")]
    #[QueryParameter('filter[search]', 'Credit-note or invoice number, order/RMA reference or customer name contains.', type: 'string')]
    #[QueryParameter('filter[issued_from]', 'Issued on or after this Dhaka date (Y-m-d).', type: 'string')]
    #[QueryParameter('filter[issued_to]', 'Issued on or before this Dhaka date (Y-m-d).', type: 'string')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'issued_at'|'-issued_at'|'number'|'-number'|'total'|'-total'", default: '-issued_at')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditNote::class);

        $sort = $request->query('sort', '-issued_at');
        $descending = ! is_string($sort) || str_starts_with($sort, '-');

        $notes = QueryBuilder::for(CreditNote::query(), $request)
            ->allowedFilters(
                AllowedFilter::callback('invoice', static fn (Builder $query, mixed $value) => $query->where('invoice_id', self::id('invoice', $value))),
                AllowedFilter::callback('order', static fn (Builder $query, mixed $value) => $query->where('order_id', self::id('order', $value))),
                AllowedFilter::callback('reason', static function (Builder $query, mixed $value): void {
                    $reason = is_string($value) ? CreditNoteReason::tryFrom($value) : null;
                    if ($reason === null) {
                        throw new BadRequestHttpException('filter[reason] must be one of: '.implode(', ', CreditNoteReason::values()).'.');
                    }
                    $query->where('reason', $reason->value);
                }),
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = trim(is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value);
                    $like = '%'.addcslashes($term, '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('number', 'like', $like)
                        ->orWhere('invoice_number', 'like', $like)
                        ->orWhere('source_reference', 'like', $like)
                        ->orWhere('issued_to->name', 'like', $like));
                }),
                AllowedFilter::callback('issued_from', static function (Builder $query, mixed $value): void {
                    $query->where('issued_at', '>=', self::bounds('issued_from', $value)[0]);
                }),
                AllowedFilter::callback('issued_to', static function (Builder $query, mixed $value): void {
                    $query->where('issued_at', '<', self::bounds('issued_to', $value)[1]);
                }),
            )
            ->allowedSorts('issued_at', 'number', 'total')
            ->defaultSort('-issued_at')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return CreditNoteListItemResource::collection($notes);
    }

    /** `GET /credit-notes/{creditNote}` — the snapshot: invoice credited, company, recipient (PII masked without customers.view_pii), lines, totals. */
    public function show(CreditNote $creditNote): CreditNoteResource
    {
        $this->authorize('view', $creditNote);

        return new CreditNoteResource($creditNote);
    }

    /** `GET /credit-notes/{creditNote}/pdf` — reprint from the snapshot; the customer's language unless `locale` says otherwise. */
    #[OpenApiResponse(200, 'The credit note PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function pdf(InvoicePdfRequest $request, CreditNote $creditNote, OrderInvoicePdf $pdf): Response
    {
        $this->authorize('print', $creditNote);
        $locale = $request->locale() ?? (($creditNote->issued_to['preferred_language'] ?? 'bn') === 'en' ? 'en' : 'bn');

        return OrderInvoiceController::pdf(
            $pdf->renderCreditNotes([['credit_note' => $creditNote, 'locale' => $locale]]),
            $creditNote->number.'.pdf',
            ['X-Invoice-Numbers' => $creditNote->number],
        );
    }

    private static function id(string $name, mixed $value): int
    {
        if (! is_string($value) || preg_match('/^[1-9]\d{0,18}$/', $value) !== 1) {
            throw new BadRequestHttpException("filter[{$name}] must be a positive integer id.");
        }

        return (int) $value;
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
