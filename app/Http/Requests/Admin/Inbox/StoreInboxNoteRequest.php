<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inbox;

use App\Domain\Inbox\Models\Conversation;
use App\Http\Requests\ApiFormRequest;

/** `POST /inbox/conversations/{conversation}/notes` — an internal note (never sent), needs inbox.reply. */
final class StoreInboxNoteRequest extends ApiFormRequest
{
    public const int MAX_LENGTH = 2000;

    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && ($this->user()?->can('note', $conversation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    public function body(): string
    {
        return trim($this->string('body')->toString());
    }
}
