<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance\Capital;

use App\Domain\Finance\Enums\CapitalHolderRole;
use App\Domain\Finance\Models\CapitalHolder;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * A capital holder, for `POST /finance/capital/holders` and `PATCH …/{holder}`: `name`
 * (unique), optional `name_bn`, `role` (`owner`|`partner`, default owner), optional `user_id` (a staff account, one holder per account)
 * and `is_active`. Needs finance.manage_capital.
 */
final class CapitalHolderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $holder = $this->holder();

        return $holder === null
            ? ($this->user()?->can('create', CapitalHolder::class) ?? false)
            : ($this->user()?->can('update', $holder) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $holder = $this->holder();
        $required = $holder === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:2', 'max:120', Rule::unique('capital_holders', 'name')->ignore($holder?->id)],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'role' => ['sometimes', Rule::enum(CapitalHolderRole::class)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('capital_holders', 'user_id')->ignore($holder?->id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function holderAttributes(): array
    {
        $attributes = $this->safe()->only(['name', 'name_bn', 'role', 'user_id', 'is_active']);
        foreach (['name', 'name_bn'] as $field) {
            if (isset($attributes[$field]) && is_string($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]) === '' ? null : trim($attributes[$field]);
            }
        }

        return $attributes;
    }

    private function holder(): ?CapitalHolder
    {
        $holder = $this->route('holder');

        return $holder instanceof CapitalHolder ? $holder : null;
    }
}
