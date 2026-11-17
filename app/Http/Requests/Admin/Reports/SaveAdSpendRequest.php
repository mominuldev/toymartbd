<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reports;

use App\Domain\Marketing\Models\AdSpend;
use App\Http\Requests\Admin\Finance\ExpenseRequest;
use App\Http\Requests\ApiFormRequest;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Validation\Validator;

/**
 * `POST /analytics/marketing/spend` and `PUT /analytics/marketing/spend/{adSpend}` — ad spend
 * typed in by staff: `campaign` (the `utm_campaign` value it pays for, ≤ 128), optional
 * `source` (facebook, google …, ≤ 64), `month` (YYYY-MM, a Dhaka month from 2020-01 up to the
 * current one), `amount` (a decimal string > 0, two places at most) and an optional `note`.
 * One row per campaign and month: a second one for the same pair is a 422 on `month`
 * (campaign compared trimmed and case-insensitively). Needs marketing.manage.
 */
final class SaveAdSpendRequest extends ApiFormRequest
{
    public const string FIRST_MONTH = '2020-01';

    public function authorize(): bool
    {
        $spend = $this->route('adSpend');

        return $spend instanceof AdSpend
            ? ($this->user()?->can('update', $spend) ?? false)
            : ($this->user()?->can('create', AdSpend::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'campaign' => ['required', 'string', 'max:128', 'not_regex:/^\s*$/'],
            'source' => ['nullable', 'string', 'max:64'],
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => ['required', 'string', 'regex:'.ExpenseRequest::MONEY_PATTERN, 'not_regex:/^0+(\.0+)?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('month') || $validator->errors()->has('campaign')) {
                return;
            }
            $month = (string) $this->input('month');
            if ($month < self::FIRST_MONTH || $month > substr(DhakaDate::today(), 0, 7)) {
                $validator->errors()->add('month', (string) __('ad_spend.month_out_of_range'));

                return;
            }
            $current = $this->route('adSpend');
            $taken = AdSpend::query()
                ->whereRaw('LOWER(campaign) = ?', [mb_strtolower($this->campaign())])
                ->whereDate('month', $this->monthStart())
                ->when($current instanceof AdSpend, static fn ($q) => $q->whereKeyNot($current instanceof AdSpend ? $current->id : 0))
                ->exists();
            if ($taken) {
                $validator->errors()->add('month', (string) __('ad_spend.taken'));
            }
        }];
    }

    public function campaign(): string
    {
        return trim((string) $this->validated('campaign', $this->input('campaign')));
    }

    public function source(): ?string
    {
        $v = $this->validated('source');

        return is_string($v) && trim($v) !== '' ? mb_strtolower(trim($v)) : null;
    }

    /** The first day of the month, Y-m-d. */
    public function monthStart(): string
    {
        return (string) $this->input('month').'-01';
    }

    public function amount(): Money
    {
        return Money::of((string) $this->validated('amount'));
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
