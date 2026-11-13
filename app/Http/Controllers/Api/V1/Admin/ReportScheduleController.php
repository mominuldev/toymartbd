<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\Actions\DeleteReportSchedule;
use App\Domain\Reporting\Actions\SaveReportSchedule;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Requests\Admin\Reports\ReportScheduleRequest;
use App\Http\Resources\Admin\Reports\ReportScheduleResource;
use App\Http\Resources\Admin\Reports\ReportScheduleRunResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Scheduled report emails (P8): CRUD, the recent sent-log, and the staff who may receive a
 * report type. reports.view + reports.export (ReportSchedulePolicy).
 */
final class ReportScheduleController extends ApiController
{
    /** `GET /reports/schedules` */
    #[OpenApiResponse(403, 'Missing reports.view or reports.export (`forbidden`)')]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ReportSchedule::class);

        return ReportScheduleResource::collection(
            ReportSchedule::query()->with(['recipients', 'latestRun'])->orderBy('name')->orderBy('id')->get(),
        );
    }

    /** `POST /reports/schedules` */
    #[OpenApiResponse(403, 'Missing reports.view or reports.export (`forbidden`)')]
    #[OpenApiResponse(422, 'Invalid schedule, a report type the actor cannot open, or a recipient who may not receive it (`validation-error`)')]
    public function store(ReportScheduleRequest $request, SaveReportSchedule $save): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return (new ReportScheduleResource($save->handle($request->scheduleData(), $user)))->response()->setStatusCode(201);
    }

    /** `GET /reports/schedules/{schedule}` */
    public function show(ReportSchedule $schedule): ReportScheduleResource
    {
        $this->authorize('view', $schedule);

        return new ReportScheduleResource($schedule->load(['recipients', 'latestRun']));
    }

    /** `PUT /reports/schedules/{schedule}` — the full document. */
    #[OpenApiResponse(422, 'As on POST (`validation-error`)')]
    public function update(ReportScheduleRequest $request, ReportSchedule $schedule, SaveReportSchedule $save): ReportScheduleResource
    {
        $user = $request->user();
        assert($user instanceof User);

        return new ReportScheduleResource($save->handle($request->scheduleData(), $user, $schedule));
    }

    /** `DELETE /reports/schedules/{schedule}` — with its sent-log. */
    public function destroy(ReportSchedule $schedule, DeleteReportSchedule $delete): Response
    {
        $this->authorize('delete', $schedule);
        $delete->handle($schedule);

        return response()->noContent();
    }

    /** `GET /reports/schedules/{schedule}/runs` — the last 20 sends, newest period first. */
    public function runs(ReportSchedule $schedule): AnonymousResourceCollection
    {
        $this->authorize('view', $schedule);

        return ReportScheduleRunResource::collection($schedule->runs()->limit(20)->get());
    }

    /**
     * `GET /reports/schedules/recipients` — active staff who may receive a report type, by
     * name and role (no email addresses).
     */
    #[QueryParameter('report_type', 'sales, pnl or couriers.', type: "'sales'|'pnl'|'couriers'", default: 'sales')]
    #[OpenApiResponse(400, 'Unlisted parameter or an unknown report type (`bad-request`)')]
    public function recipients(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReportSchedule::class);
        $type = ReportType::from(ReportQuery::from($request, [], ['report_type'])->choice('report_type', ReportType::values(), ReportType::Sales->value));

        $users = User::query()->where('is_active', true)->with('roles')->orderBy('name')->get()
            ->filter(static fn (User $user): bool => $type->canReceive($user))
            ->map(static fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name, 'roles' => $user->getRoleNames()->values()->all()])
            ->values()
            ->all();

        return response()->json(['data' => $users]);
    }
}
