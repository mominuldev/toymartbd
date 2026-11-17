<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Ai;

use App\Domain\Ai\Inbox\DraftInboxReply;
use App\Domain\Inbox\Models\Conversation;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /inbox/conversations/{conversation}/ai-draft` — a suggested reply; needs inbox.reply
 * (the conversation policy's `reply`). Nothing is sent.
 */
final class DraftInboxReplyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && ($this->user()?->can('reply', $conversation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** `default` (the customer's language, 3 sentences at most), `shorter`, `english` or `bangla`. */
            'style' => ['sometimes', 'nullable', 'string', Rule::in(DraftInboxReply::STYLES)],
        ];
    }

    public function style(): string
    {
        $style = $this->input('style');

        return is_string($style) && $style !== '' ? $style : 'default';
    }
}
