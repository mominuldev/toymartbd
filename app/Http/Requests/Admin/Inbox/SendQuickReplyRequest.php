<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inbox;

use App\Domain\Inbox\Actions\SendQuickReply;
use App\Domain\Inbox\Models\Conversation;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /inbox/conversations/{conversation}/quick-replies` — needs inbox.reply. The server
 * composes the text: `product_card` (target = product id) or `tracking_link` (target = an
 * order of the conversation's customer).
 */
final class SendQuickReplyRequest extends ApiFormRequest
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
            /** @var 'product_card'|'tracking_link' */
            'kind' => ['required', 'string', Rule::in(SendQuickReply::KINDS)],
            'target_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
