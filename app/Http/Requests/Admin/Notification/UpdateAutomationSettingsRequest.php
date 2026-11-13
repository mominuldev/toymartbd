<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Services\AutomationSettings;
use App\Http\Requests\Admin\Coupon\CouponRequest;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /notifications/automations` — Settings → Automations scheduling (P8); needs
 * settings.update. Whether each automation runs is its templates' on/off switch. P8 batch 3:
 * the birthday reminder's lead days and its offer — optional; left out, they stay as they are.
 */
final class UpdateAutomationSettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Days after delivery before the review request goes out (1–30). */
            'review_delay_days' => ['required', 'integer', 'min:1', 'max:30'],
            /** Dhaka quiet hours start, HH:MM (24 h). Nothing automated is sent inside the window. */
            'quiet_from' => ['required', 'string', 'date_format:H:i'],
            /** Dhaka quiet hours end, HH:MM (24 h); may be earlier than the start (wraps midnight). */
            'quiet_to' => ['required', 'string', 'date_format:H:i', 'different:quiet_from'],
            /** Days before each child's birthday the parent is reminded (0 = on the day, up to 30). */
            'birthday_lead_days' => ['sometimes', 'integer', 'min:0', 'max:'.AutomationSettings::MAX_LEAD_DAYS],
            /** The personal coupon in each reminder. Omit to leave it as it is. */
            'birthday_offer' => ['sometimes', 'array:enabled,type,value,max_discount,min_order_amount,valid_days'],
            /** Off until the owner turns it on: the reminder then goes without a code. */
            'birthday_offer.enabled' => ['required_with:birthday_offer', 'boolean'],
            /** @var 'percent'|'fixed' */
            'birthday_offer.type' => ['required_with:birthday_offer', Rule::in(['percent', 'fixed'])],
            /** Percent (above 0, at most 100) or taka off, as a decimal string. */
            'birthday_offer.value' => ['required_with:birthday_offer', 'string', 'regex:'.CouponRequest::MONEY_PATTERN],
            /** Percent offers only: the most taka it takes off. Null = no cap. */
            'birthday_offer.max_discount' => ['nullable', 'string', 'regex:'.CouponRequest::MONEY_PATTERN],
            /** Goods total the code needs; "0.00" = none. */
            'birthday_offer.min_order_amount' => ['required_with:birthday_offer', 'string', 'regex:'.CouponRequest::MONEY_PATTERN],
            /** Whole Dhaka days the code works after the reminder (1–90). */
            'birthday_offer.valid_days' => ['required_with:birthday_offer', 'integer', 'min:1', 'max:'.AutomationSettings::MAX_OFFER_VALID_DAYS],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('birthday_offer')) {
                return;
            }
            $value = Money::of((string) $this->input('birthday_offer.value'));
            $percent = $this->input('birthday_offer.type') === 'percent';
            if (! $value->greaterThan(Money::zero()) || ($percent && $value->greaterThan(Money::of(100)))) {
                $validator->errors()->add('birthday_offer.value', $percent ? 'A percent offer needs a value above 0 and at most 100.' : 'A fixed offer needs a value above 0.');
            }
        }];
    }

    public function leadDays(): ?int
    {
        return $this->has('birthday_lead_days') ? $this->integer('birthday_lead_days') : null;
    }

    /** @return array{enabled: bool, type: 'percent'|'fixed', value: string, max_discount: string|null, min_order_amount: string, valid_days: int}|null */
    public function offer(): ?array
    {
        if (! $this->has('birthday_offer')) {
            return null;
        }

        return [
            'enabled' => $this->boolean('birthday_offer.enabled'),
            'type' => $this->input('birthday_offer.type') === 'fixed' ? 'fixed' : 'percent',
            'value' => Money::of((string) $this->input('birthday_offer.value'))->toDecimal(),
            'max_discount' => $this->filled('birthday_offer.max_discount') ? Money::of((string) $this->input('birthday_offer.max_discount'))->toDecimal() : null,
            'min_order_amount' => Money::of((string) $this->input('birthday_offer.min_order_amount'))->toDecimal(),
            'valid_days' => $this->integer('birthday_offer.valid_days'),
        ];
    }

    public function delayDays(): int
    {
        return (int) $this->input('review_delay_days');
    }

    public function quietFrom(): string
    {
        return (string) $this->input('quiet_from');
    }

    public function quietTo(): string
    {
        return (string) $this->input('quiet_to');
    }
}
