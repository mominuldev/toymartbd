<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Risk;

use App\Domain\Risk\Enums\RiskProfileStatus;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Storefront\Account\PhoneRule;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Validation\Rule;

/**
 * `PUT /risk/phone-status` — put a phone on a list (or back to normal): `phone`, `status`
 * (normal|watch|blacklist|whitelist) and a `reason` (required, recorded with who and when).
 * Blacklisting also needs `confirm` equal to the phone's last four digits (the checklist's
 * typed confirmation). Needs risk.manage_lists.
 */
final class SetPhoneStatusRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('setStatus', CustomerRiskProfile::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32', PhoneRule::make()],
            'status' => ['required', 'string', Rule::in(RiskProfileStatus::values())],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => ['nullable', 'string', 'max:8', 'required_if:status,blacklist'],
        ];
    }

    /** @return list<Closure(\Illuminate\Validation\Validator): void> */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('status') !== RiskProfileStatus::Blacklist->value) {
                return;
            }
            $phone = PhoneNumber::tryParse((string) $this->input('phone'));
            if ($phone === null || $this->input('confirm') !== $phone->lastDigits(4)) {
                $validator->errors()->add('confirm', (string) __('validation.same', ['attribute' => 'confirm', 'other' => 'last 4 digits']));
            }
        }];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse((string) $this->validated('phone'));
    }

    public function status(): RiskProfileStatus
    {
        return RiskProfileStatus::from((string) $this->validated('status'));
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
