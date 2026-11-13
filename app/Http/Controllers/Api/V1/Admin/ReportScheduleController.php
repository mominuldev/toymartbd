<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\Actions\DeleteReportSchedule;
use App\Domain\Reporting\Actions\ResendReportRun;
use App\Domain\Reporting\Actions\SaveReportSchedule;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Requests\Admin\Reports\ReportScheduleRequest;
use App\Http\Requests\Admin\Reports\ResendReportRunRequest;
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
 * Scheduled report emails (P8): CRUD, the recent sent-log with each recipient's copy, a
 * staff resend of a failed run (P9), and the staff who may receive a report type.
 * reports.view + reports.export (ReportSchedulePolicy).
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

        return ReportScheduleRunResource::collection($schedule->runs()->with(ReportScheduleRunResource::RELATIONS)->limit(20)->get());
    }

    /**
     * `POST /reports/schedules/{schedule}/runs/{run}/resend` (P9) — resend a FAILED run for the
     * same period: every copy that failed, or one recipient's (`user_id`). Queued on `reports`;
     * a copy already sent is never mailed again. Idempotent: a run already queued or sending
     * queues nothing more and answers 202 with its state.
     */
    #[OpenApiResponse(403, 'Missing reports.view or reports.export (`forbidden`)')]
    #[OpenApiResponse(409, 'The run did not fail, the copy was already sent, nothing is left to resend, or an older run must be resent one recipient at a time (`conflict`)')]
    #[OpenApiResponse(422, 'The recipient is not on the schedule, inactive, or no longer allowed to see the report (`validation-error`)')]
    public function resend(ResendReportRunRequest $request, ReportSchedule $schedule, ReportScheduleRun $run, ResendReportRun $resend): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $resent = $resend->handle($schedule, $run, $user, $request->userId());

        return (new ReportScheduleRunResource($resent->load(ReportScheduleRunResource::RELATIONS)))->response()->setStatusCode(202);
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
