<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use App\Support\Money;

/** `PUT /settings/pos` — Store settings › Point of sale (the whole section); needs settings.update. */
final class UpdatePosSettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The most a cashier may take off a sale, as a percent of the goods after promotions and the coupon, e.g. `10`. `0` turns cashier discounts off. */
            'discount_max_percent' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'min:0', 'max:100'],
            /** The most one customer may owe from POS sales on due, in taka, e.g. `5000.00`. `0` stops new sales on due. */
            'due_limit' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ];
    }

    public function dueLimit(): Money
    {
        return Money::of($this->string('due_limit')->trim()->toString());
    }

    public function maxPercent(): string
    {
        return $this->string('discount_max_percent')->trim()->toString();
    }
}
