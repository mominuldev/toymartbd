<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Notification\Actions\RetryNotification;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Queries\NotificationSpend;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Notification\RetryNotificationRequest;
use App\Http\Resources\Admin\NotificationLogResource;
use App\Http\Resources\Admin\NotificationSummaryResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The notification log (P4-NOT-01): every message attempted, with its per-message cost,
 * and the dead letters staff must act on. Recipients are masked at write time.
 */
final class NotificationLogController extends ApiController
{
    public const int PER_PAGE = 50;

    /**
     * `GET /notifications/logs` — newest first, cursor-paginated; needs settings.view.
     * Filters: `filter[status]`, `filter[channel]`, `filter[template]`, `filter[order_id]`.
     */
    #[QueryParameter('filter[status]', 'One status.', type: "'queued'|'sent'|'failed'|'dead_lettered'|'skipped'")]
    #[QueryParameter('filter[channel]', 'One channel.', type: "'sms'|'whatsapp'|'email'")]
    #[QueryParameter('filter[template]', 'Template key, e.g. order.placed.', type: 'string')]
    #[QueryParameter('filter[order_id]', 'Messages about one order.', type: 'integer')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', NotificationLog::class);

        if ($request->has('sort')) {
            throw new BadRequestHttpException('The log is always newest first; sorting is not supported.');
        }

        $logs = QueryBuilder::for(NotificationLog::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || NotificationStatus::tryFrom($value) === null) {
                        throw new BadRequestHttpException('filter[status] must be one of: '.implode(', ', NotificationStatus::values()).'.');
                    }
                    $query->where('status', $value);
                }),
                AllowedFilter::callback('channel', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || NotificationChannel::tryFrom($value) === null) {
                        throw new BadRequestHttpException('filter[channel] must be one of: '.implode(', ', NotificationChannel::values()).'.');
                    }
                    $query->where('channel', $value);
                }),
                AllowedFilter::callback('template', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || preg_match('/^[a-z0-9_.]{1,64}$/', $value) !== 1) {
                        throw new BadRequestHttpException('filter[template] must be a template key.');
                    }
                    $query->where('template', $value);
                }),
                AllowedFilter::callback('order_id', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || ! ctype_digit($value)) {
                        throw new BadRequestHttpException('filter[order_id] must be an integer.');
                    }
                    $query->where('notifiable_type', NotificationLog::NOTIFIABLE_ORDER)->where('notifiable_id', (int) $value);
                }),
            )
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return NotificationLogResource::collection($logs);
    }

    /** `GET /notifications/logs/summary` — this Dhaka month's spend per channel + dead letters; needs settings.view. */
    public function summary(NotificationSpend $spend): NotificationSummaryResource
    {
        $this->authorize('viewAny', NotificationLog::class);

        return new NotificationSummaryResource($spend->forMonth());
    }

    /** `POST /notifications/logs/{log}/retry` — re-queue a dead letter; needs settings.update. */
    #[OpenApiResponse(409, 'The message is not dead-lettered (`conflict`)')]
    public function retry(RetryNotificationRequest $request, NotificationLog $log, RetryNotification $retry): NotificationLogResource
    {
        try {
            return new NotificationLogResource($retry->handle($log)->refresh());
        } catch (InvalidArgumentException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }
    }
}
