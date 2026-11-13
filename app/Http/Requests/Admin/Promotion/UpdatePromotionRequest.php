<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

/** `PUT /promotions/{promotion}` — the whole document; needs marketing.manage. */
final class UpdatePromotionRequest extends PromotionRequest
{
    public function authorize(): bool
    {
        $promotion = $this->route('promotion');

        return $promotion !== null && ($this->user()?->can('update', $promotion) ?? false);
    }
}
