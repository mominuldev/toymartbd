<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Risk;

use App\Domain\Risk\Enums\RiskFactor;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Http\Requests\Admin\Finance\ExpenseRequest;
use App\Http\Requests\ApiFormRequest;
use Closure;

/**
 * `PUT /risk/rules` — the Risk rules form: one weight per factor (0–100, totalling ≤ 100 so
 * the breakdown always adds up to the score), `medium_threshold` < `high_threshold` (1–100),
 * `first_order_hold_threshold` (BDT, rule 33b), `value_norm_multiplier` (1–20) and
 * `rapid_repeat_minutes` (1–1440). Needs risk.manage_rules.
 */
final class UpdateRiskRulesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageRules', CustomerRiskProfile::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'weights' => ['required', 'array:'.implode(',', RiskFactor::values())],
            'medium_threshold' => ['required', 'integer', 'min:1', 'max:99', 'lt:high_threshold'],
            'high_threshold' => ['required', 'integer', 'min:2', 'max:100'],
            'first_order_hold_threshold' => ['required', 'string', 'regex:'.ExpenseRequest::MONEY_PATTERN],
            'value_norm_multiplier' => ['required', 'numeric', 'min:1', 'max:20'],
            'rapid_repeat_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
        foreach (RiskFactor::values() as $code) {
            $rules['weights.'.$code] = ['required', 'integer', 'min:0', 'max:100'];
        }

        return $rules;
    }

    /** @return list<Closure(\Illuminate\Validation\Validator): void> */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            $weights = $this->input('weights');
            if (! is_array($weights)) {
                return;
            }
            $total = array_sum(array_map(static fn (mixed $w): int => is_numeric($w) ? (int) $w : 0, $weights));
            if ($total > 100) {
                $validator->errors()->add('weights', (string) __('validation.max.numeric', ['attribute' => 'weights total', 'max' => 100]));
            }
        }];
    }

    /**
     * @return array{weights: array<string, int>, medium_threshold: int, high_threshold: int, first_order_hold_threshold: string, value_norm_multiplier: string, rapid_repeat_minutes: int}
     */
    public function rulesDocument(): array
    {
        /** @var array<string, mixed> $weights */
        $weights = $this->validated('weights');
        $clean = [];
        foreach (RiskFactor::values() as $code) {
            $clean[$code] = (int) $weights[$code];
        }

        return [
            'weights' => $clean,
            'medium_threshold' => (int) $this->validated('medium_threshold'),
            'high_threshold' => (int) $this->validated('high_threshold'),
            'first_order_hold_threshold' => (string) $this->validated('first_order_hold_threshold'),
            'value_norm_multiplier' => (string) $this->validated('value_norm_multiplier'),
            'rapid_repeat_minutes' => (int) $this->validated('rapid_repeat_minutes'),
        ];
    }
}
