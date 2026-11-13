<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Dashboard;

use App\Domain\Reporting\Queries\Dashboard\DashboardReport;
use App\Domain\Reporting\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `GET /dashboard?range=…&from=…&to=…` (rule 14 + rule 16).
 *
 *   range  today | 7d | 30d | this_month | last_month | custom   (default 7d)
 *   from   YYYY-MM-DD, Dhaka date, inclusive — range=custom only (and then required)
 *   to     YYYY-MM-DD, Dhaka date, inclusive — range=custom only (and then required)
 *
 * Anything outside the allowlist — an unknown parameter, an unlisted preset, a date with a
 * preset, a malformed or impossible date, a range longer than ReportPeriod::MAX_DAYS — is a
 * 400 `bad-request`, never silently ignored. Authorisation: dashboard.view.
 * {@see DashboardExportRequest} extends the allowlist with `format` and `locale`.
 */
class DashboardRequest extends FormRequest
{
    public const array PARAMETERS = ['range', 'from', 'to'];

    final public function authorize(): bool
    {
        return $this->user()?->can('view', DashboardReport::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'range' => ['sometimes', 'string'],
            'from' => ['sometimes', 'string'],
            'to' => ['sometimes', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($this->query->all())), static::PARAMETERS);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown parameter(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', static::PARAMETERS).'.');
        }
        foreach (static::PARAMETERS as $name) {
            if ($this->query->has($name) && ! is_string($this->query->all()[$name])) {
                throw new BadRequestHttpException("`{$name}` must be a single value.");
            }
        }
    }

    /** The Dhaka date range the dashboard covers. */
    final public function dateRange(): DateRange
    {
        $value = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        try {
            return DateRange::fromPreset($value($this->query('range')), $value($this->query('from')), $value($this->query('to')));
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }
}
