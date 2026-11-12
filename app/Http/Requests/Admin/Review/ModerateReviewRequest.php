<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Review;

use App\Domain\Catalog\Reviews\Models\Review;
use App\Http\Requests\ApiFormRequest;

/** `POST /reviews/{review}/approve` and `/reject` — needs reviews.moderate. No body. */
final class ModerateReviewRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $review instanceof Review && ($this->user()?->can('moderate', $review) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
