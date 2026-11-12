<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Models\Order;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /orders/bulk/invoices` and `GET /orders/{order}/invoice` — the invoice /
 * packing-slip PDF (P4-ORD-08). Needs orders.view AND customers.view_pii: the slip
 * carries the full phone and street address the rider needs. `locale` picks the
 * language (default: each customer's preferred language).
 */
final class OrderInvoicesRequest extends ApiFormRequest
{
    public const int MAX_ORDERS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('printInvoices', Order::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_ids' => [$this->route('order') === null ? 'required' : 'prohibited', 'array', 'min:1', 'max:'.self::MAX_ORDERS],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'locale' => ['nullable', 'string', Rule::in(['en', 'bn'])],
        ];
    }

    /** @return list<int> */
    public function orderIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = array_values((array) $this->input('order_ids', []));

        return array_map(intval(...), $ids);
    }

    public function locale(): ?string
    {
        $locale = $this->input('locale');

        return is_string($locale) ? $locale : null;
    }
}
