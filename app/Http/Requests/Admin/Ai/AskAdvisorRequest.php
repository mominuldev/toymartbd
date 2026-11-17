<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Ai;

use App\Domain\Ai\Advisor\Models\AiBrief;
use App\Http\Requests\ApiFormRequest;

/** `POST /analytics/advisor/ask` — one question for the Growth advisor; needs reports.view. */
final class AskAdvisorRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', AiBrief::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The owner's question, in English or Bangla. Answered from report figures only. */
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function question(): string
    {
        return $this->string('question')->squish()->toString();
    }
}
