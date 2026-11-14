<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reports;

use App\Domain\Reporting\Models\ReportSchedule;
use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * `POST /reports/schedules/{schedule}/runs/{run}/resend` (P9) — resend a failed run for the
 * same period. Needs reports.view + reports.export (ReportSchedulePolicy::resend), and the
 * actor must be able to open the report type themselves.
 *
 * - no body (or `user_id: null`): every copy that failed or never went out;
 * - `user_id`: that one recipient's copy — a current recipient of the schedule, still active
 *   and still holding the report's permissions (re-checked now, and again at send time).
 */
final class ResendReportRunRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $schedule = $this->route('schedule');

        return $schedule instanceof ReportSchedule && ($this->user()?->can('resend', $schedule) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** One recipient's copy only; omit for every failed copy. */
            'user_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $schedule = $this->route('schedule');
            assert($schedule instanceof ReportSchedule);
            $actor = $this->user();
            if ($actor instanceof User && ! $schedule->report_type->canReceive($actor)) {
                $validator->errors()->add('user_id', 'You cannot resend a report you are not allowed to open.');

                return;
            }
            $userId = $this->userId();
            if ($userId === null) {
                return;
            }
            $recipient = $schedule->recipients()->whereKey($userId)->first();
            if (! $recipient instanceof User) {
                $validator->errors()->add('user_id', 'This person is not a recipient of the schedule.');
            } elseif (! $schedule->report_type->canReceive($recipient)) {
                $validator->errors()->add('user_id', "{$recipient->name} is inactive or no longer allowed to see this report.");
            }
        }];
    }

    public function userId(): ?int
    {
        $value = $this->input('user_id');

        return is_numeric($value) ? (int) $value : null;
    }
}
