<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Enums\TrackingSource;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Queries\TrackingDiagnostics;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\TrackingEventDetailResource;
use App\Http\Resources\Admin\TrackingEventResource;
use App\Http\Resources\Admin\TrackingSummaryResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Admin → Integrations → Tracking → Diagnostics (PRD K-03): the event log, one event as
 * sent, and the per-destination health summary — so an under-reported conversion is
 * diagnosable, not guessed at. Read-only; needs settings.view ({@see \App\Policies\TrackingEventPolicy}
 * explains why not reports.view).
 */
final class TrackingEventsController extends ApiController
{
    public const int PER_PAGE = 50;

    /**
     * `GET /tracking/events` — newest first, cursor-paginated. Filters are an allowlist; an
     * unlisted filter, a sort, or a bad value is a 400. Dates are Dhaka calendar dates
     * (rule 11), inclusive.
     */
    #[QueryParameter('filter[event_name]', 'One event.', type: "'page_view'|'view_item'|'add_to_cart'|'begin_checkout'|'purchase'|'refund'")]
    #[QueryParameter('filter[source]', 'Where the event originated.', type: "'browser'|'server'")]
    #[QueryParameter('filter[ga4_status]', 'GA4 dispatch status.', type: "'pending'|'sent'|'failed'|'skipped_consent'|'skipped_disabled'|'skipped_stale'|'not_applicable'")]
    #[QueryParameter('filter[meta_status]', 'Meta dispatch status.', type: "'pending'|'sent'|'failed'|'skipped_consent'|'skipped_disabled'|'skipped_stale'|'not_applicable'")]
    #[QueryParameter('filter[date_from]', 'First Dhaka date (Y-m-d), inclusive.', type: 'string')]
    #[QueryParameter('filter[date_to]', 'Last Dhaka date (Y-m-d), inclusive.', type: 'string')]
    #[QueryParameter('filter[order_number]', 'Exact order number, e.g. TMB-26-00042.', type: 'string')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TrackingEvent::class);

        if ($request->has('sort')) {
            throw new BadRequestHttpException('The event log is always newest first; sorting is not supported.');
        }

        $status = static fn (string $column): AllowedFilter => AllowedFilter::callback($column, static function (Builder $query, mixed $value) use ($column): void {
            if (! is_string($value) || DispatchStatus::tryFrom($value) === null) {
                throw new BadRequestHttpException("filter[{$column}] must be one of: ".implode(', ', array_column(DispatchStatus::cases(), 'value')).'.');
            }
            $query->where($column, $value);
        });

        $events = QueryBuilder::for(TrackingEvent::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('event_name', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || TrackingEventName::tryFrom($value) === null) {
                        throw new BadRequestHttpException('filter[event_name] must be one of: '.implode(', ', array_column(TrackingEventName::cases(), 'value')).'.');
                    }
                    $query->where('event_name', $value);
                }),
                AllowedFilter::callback('source', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || TrackingSource::tryFrom($value) === null) {
                        throw new BadRequestHttpException('filter[source] must be browser or server.');
                    }
                    $query->where('source', $value);
                }),
                $status('ga4_status'),
                $status('meta_status'),
                AllowedFilter::callback('date_from', static function (Builder $query, mixed $value): void {
                    $query->where('created_at', '>=', self::bounds($value, 'date_from')[0]);
                }),
                AllowedFilter::callback('date_to', static function (Builder $query, mixed $value): void {
                    $query->where('created_at', '<', self::bounds($value, 'date_to')[1]);
                }),
                AllowedFilter::callback('order_number', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || preg_match('/^[A-Za-z0-9-]{1,20}$/', $value) !== 1) {
                        throw new BadRequestHttpException('filter[order_number] must be an order number.');
                    }
                    $query->whereHas('order', static fn (Builder $order) => $order->where('number', strtoupper($value)));
                }),
            )
            ->with('order:id,number')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return TrackingEventResource::collection($events);
    }

    /** `GET /tracking/events/{event}` — one event, its payload as sent and each destination's response. */
    public function show(TrackingEvent $event): TrackingEventDetailResource
    {
        $this->authorize('view', $event);

        return new TrackingEventDetailResource($event->load('order:id,number'));
    }

    /** `GET /tracking/summary` — per-destination health: 24 h / 7 d counts, consent-skip and pairing rates, last success / error. */
    public function summary(TrackingDiagnostics $diagnostics): TrackingSummaryResource
    {
        $this->authorize('viewAny', TrackingEvent::class);

        return new TrackingSummaryResource($diagnostics->summary());
    }

    /** @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable} */
    private static function bounds(mixed $value, string $name): array
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new BadRequestHttpException("filter[{$name}] must be a Y-m-d date.");
        }
        try {
            return DhakaDate::utcBounds($value);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException("filter[{$name}] must be a Y-m-d date.", $e);
        }
    }
}
