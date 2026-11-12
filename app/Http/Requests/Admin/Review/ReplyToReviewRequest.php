<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Review;

use App\Domain\Catalog\Reviews\Models\Review;
use App\Http\Requests\ApiFormRequest;

/** `PUT /reviews/{review}/reply` — the shop's public reply; null or "" removes it. Needs reviews.moderate. */
final class ReplyToReviewRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $review instanceof Review && ($this->user()?->can('moderate', $review) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reply' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function reply(): ?string
    {
        $reply = $this->validated('reply');

        return is_string($reply) && trim($reply) !== '' ? trim($reply) : null;
    }
}
