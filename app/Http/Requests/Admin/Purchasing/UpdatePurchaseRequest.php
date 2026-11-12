<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Purchasing\Models\Purchase;

/** `PUT /purchases/{purchase}` — replaces a draft's header and lines (409 once ordered); needs purchasing.create. */
final class UpdatePurchaseRequest extends PurchaseDocumentRequest
{
    public function authorize(): bool
    {
        $purchase = $this->route('purchase');

        return $purchase instanceof Purchase && ($this->user()?->can('update', $purchase) ?? false);
    }
}
