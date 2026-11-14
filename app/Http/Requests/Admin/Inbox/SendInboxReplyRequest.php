<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inbox;

use App\Domain\Inbox\Drivers\WhatsAppCloudDriver;
use App\Domain\Inbox\Models\Conversation;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /inbox/conversations/{conversation}/messages` — a reply to the customer, needs
 * inbox.reply. Queued; sent after commit. 409 outside the 24-hour window.
 */
final class SendInboxReplyRequest extends ApiFormRequest
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
            /** WhatsApp's text limit (4096). Messenger publishes no limit we could confirm. */
            'body' => ['required', 'string', 'max:'.WhatsAppCloudDriver::MAX_BODY],
        ];
    }

    public function body(): string
    {
        return trim($this->string('body')->toString());
    }
}
