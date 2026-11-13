<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reports;

use App\Domain\Reporting\DTOs\ReportScheduleData;
use App\Domain\Reporting\Enums\ReportFileFormat;
use App\Domain\Reporting\Enums\ReportFrequency;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A scheduled report, for `POST /reports/schedules` and `PUT /reports/schedules/{schedule}`
 * (the full document both times).
 *
 * - `send_time` is HH:MM in Asia/Dhaka, on a quarter hour (the sweep runs every 15 minutes).
 * - `recipient_ids` are staff users only — never a free-typed address — each active and
 *   holding the report type's permissions (ReportType::requiredPermissions()).
 * - The actor must be able to open the report type themselves.
 */
final class ReportScheduleRequest extends ApiFormRequest
{
    public const int MAX_RECIPIENTS = 20;

    public function authorize(): bool
    {
        $schedule = $this->route('schedule');

        return $schedule instanceof ReportSchedule
            ? ($this->user()?->can('update', $schedule) ?? false)
            : ($this->user()?->can('create', ReportSchedule::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'report_type' => ['required', Rule::enum(ReportType::class)],
            'frequency' => ['required', Rule::enum(ReportFrequency::class)],
            'send_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):(00|15|30|45)$/'],
            'locale' => ['required', Rule::in(['en', 'bn'])],
            'format' => ['required', Rule::enum(ReportFileFormat::class)],
            'is_active' => ['required', 'boolean'],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'recipient_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'send_time.regex' => 'Use HH:MM on a quarter hour (00, 15, 30 or 45 minutes), Dhaka time.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $type = ReportType::from((string) $this->input('report_type'));
            $actor = $this->user();
            if ($actor instanceof User && ! $type->canReceive($actor)) {
                $validator->errors()->add('report_type', 'You cannot schedule a report you are not allowed to open.');

                return;
            }
            /** @var list<int> $ids */
            $ids = array_map(intval(...), (array) $this->input('recipient_ids'));
            foreach (User::query()->whereKey($ids)->get() as $user) {
                if (! $type->canReceive($user)) {
                    $validator->errors()->add('recipient_ids', "{$user->name} is inactive or not allowed to see this report.");
                }
            }
        }];
    }

    public function scheduleData(): ReportScheduleData
    {
        return new ReportScheduleData(
            name: trim((string) $this->validated('name')),
            reportType: ReportType::from((string) $this->validated('report_type')),
            frequency: ReportFrequency::from((string) $this->validated('frequency')),
            sendTime: (string) $this->validated('send_time'),
            locale: (string) $this->validated('locale'),
            format: ReportFileFormat::from((string) $this->validated('format')),
            isActive: (bool) $this->validated('is_active'),
            recipientIds: array_values(array_map(intval(...), (array) $this->validated('recipient_ids'))),
        );
    }
}
