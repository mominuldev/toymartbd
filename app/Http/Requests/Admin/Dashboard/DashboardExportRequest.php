<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Dashboard;

use App\Domain\Reporting\Exports\Spreadsheet;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `GET /dashboard/export?format=…&locale=…&range=…&from=…&to=…` (rule 14 + rule 16).
 *
 * The dashboard's own range parameters and validation ({@see DashboardRequest}), plus:
 *   format  csv | xlsx                   (default csv)
 *   locale  en | bn                      (default: the request's locale, else en)
 *
 * An unlisted format or locale is a 400 `bad-request`, like every other unlisted value.
 * Authorisation: dashboard.view — the export then carries only the blocks the JSON would.
 */
final class DashboardExportRequest extends DashboardRequest
{
    public const array PARAMETERS = ['range', 'from', 'to', 'format', 'locale'];

    public const array LOCALES = ['en', 'bn'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['sometimes', 'string'],
            'locale' => ['sometimes', 'string'],
        ];
    }

    public function exportFormat(): string
    {
        return $this->choice('format', Spreadsheet::formats(), Spreadsheet::CSV);
    }

    public function exportLocale(): string
    {
        $current = app()->getLocale();

        return $this->choice('locale', self::LOCALES, in_array($current, self::LOCALES, true) ? $current : 'en');
    }

    /** @param  list<string>  $allowed */
    private function choice(string $name, array $allowed, string $default): string
    {
        $value = $this->query($name);
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }
        $value = is_string($value) ? strtolower(trim($value)) : '';
        if (! in_array($value, $allowed, true)) {
            throw new BadRequestHttpException("`{$name}` must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }
}
