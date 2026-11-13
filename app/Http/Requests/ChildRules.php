<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Customer\Models\CustomerChild;

/**
 * The fields of a child (P8 batch 3), shared by the admin and storefront requests: an optional
 * first name or nickname, the birthday as month + day, and an optional birth year (only to
 * show an age). SaveCustomerChild checks the date is real (no 31 February).
 */
final class ChildRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        // Static bounds here (the OpenAPI document must not change with the calendar); the
        // action refuses a year more than 25 years back or in the future.
        return [
            /** First name or nickname — optional; nothing else about the child is stored. */
            'name' => ['present', 'nullable', 'string', 'max:'.CustomerChild::NAME_MAX],
            /** 1–12. */
            'birth_month' => ['required', 'integer', 'between:1,12'],
            /** 1–31; 29 February is fine (celebrated 28 February in other years). */
            'birth_day' => ['required', 'integer', 'between:1,31'],
            /** Optional, only to show an age; within the last 25 years. */
            'birth_year' => ['present', 'nullable', 'integer', 'between:1990,2200'],
        ];
    }

    /** @return array{name: string|null, month: int, day: int, year: int|null} */
    public static function values(ApiFormRequest $request): array
    {
        return [
            'name' => $request->filled('name') ? $request->string('name')->trim()->toString() : null,
            'month' => $request->integer('birth_month'),
            'day' => $request->integer('birth_day'),
            'year' => $request->filled('birth_year') ? $request->integer('birth_year') : null,
        ];
    }
}
