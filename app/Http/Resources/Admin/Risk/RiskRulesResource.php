<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Risk;

use App\Domain\Risk\Services\RiskSettings;
use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * The Risk rules form: factor weights (total ≤ 100), level thresholds, the first-order hold
 * threshold (rule 33b) and the two factor parameters. PROVISIONAL defaults (§5.2) until saved.
 *
 * @property RiskSettings $resource
 */
final class RiskRulesResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rules = $this->resource->toArray();

        return [
            /** @var array<string, int> */
            'weights' => $rules['weights'],
            'medium_threshold' => $rules['medium_threshold'],
            'high_threshold' => $rules['high_threshold'],
            'first_order_hold_threshold' => $rules['first_order_hold_threshold'],
            'value_norm_multiplier' => $rules['value_norm_multiplier'],
            'rapid_repeat_minutes' => $rules['rapid_repeat_minutes'],
            'can_edit' => $request->user()?->can('manageRules', \App\Domain\Risk\Models\CustomerRiskProfile::class) === true,
        ];
    }
}
