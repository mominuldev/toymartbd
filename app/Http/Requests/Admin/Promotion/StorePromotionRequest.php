<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

use App\Domain\Marketing\Models\Promotion;

/** `POST /promotions` — needs marketing.manage. */
final class StorePromotionRequest extends PromotionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Promotion::class) ?? false;
    }
}
