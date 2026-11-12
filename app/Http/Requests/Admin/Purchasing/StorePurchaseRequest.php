<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Purchasing\Models\Purchase;

/** `POST /purchases` — raises a draft PO; needs purchasing.create. */
final class StorePurchaseRequest extends PurchaseDocumentRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Purchase::class) ?? false;
    }
}
