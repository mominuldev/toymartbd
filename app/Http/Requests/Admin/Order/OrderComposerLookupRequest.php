<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Models\Order;
use App\Http\Requests\ApiFormRequest;

/**
 * The create-order screen's lookups (P4-ORD-06): customer by phone, product/variant
 * search, districts and upazilas. Needs orders.create.
 */
final class OrderComposerLookupRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Order::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The customer's phone, as typed (customer lookup). */
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            /** Name, Bangla name or SKU, at least 2 characters (variant search). */
            'q' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function term(): string
    {
        return trim($this->string('q')->toString());
    }
}
