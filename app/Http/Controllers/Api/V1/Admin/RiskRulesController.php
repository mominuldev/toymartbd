<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Risk\Actions\SaveRiskRules;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Services\RiskSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Risk\UpdateRiskRulesRequest;
use App\Http\Resources\Admin\Risk\RiskRulesResource;

/** Risk rules (checklist §2.4 "settings form pattern"): weights and thresholds of the score. */
final class RiskRulesController extends ApiController
{
    /** `GET /risk/rules` — needs risk.view. */
    public function show(RiskSettings $settings): RiskRulesResource
    {
        $this->authorize('viewAny', CustomerRiskProfile::class);

        return new RiskRulesResource($settings);
    }

    /** `PUT /risk/rules` — needs risk.manage_rules. Only future scores change. */
    public function update(UpdateRiskRulesRequest $request, SaveRiskRules $save): RiskRulesResource
    {
        return new RiskRulesResource($save->handle($request->rulesDocument(), $request->user()?->id));
    }
}
