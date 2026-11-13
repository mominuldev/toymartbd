<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Risk;

use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Storefront\Account\PhoneRule;
use App\Support\PhoneNumber;

/**
 * `GET /risk/lookup?phone=…` and `POST /risk/lookup/rescore` — a Bangladesh mobile number
 * in any accepted spelling (rule 30). Lookup needs risk.view; rescore needs risk.verify
 * (it writes the profile projection).
 */
final class PhoneLookupRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $this->isMethod('GET')
            ? $user->can('viewAny', CustomerRiskProfile::class)
            : $user->can('create', \App\Domain\Risk\Models\RiskVerificationCall::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:32', PhoneRule::make()]];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse((string) $this->validated('phone'));
    }
}
