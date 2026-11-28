<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Broadcasting;

use App\Domain\Inbox\Models\Conversation;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /broadcasting/auth` — signs a Reverb private-channel subscription for the signed-in staff
 * member. `channel_name` is an allowlist (rule 16): only the channels the admin subscribes to.
 * The only channel today is the Inbox's, so anyone without inbox.view is refused (403) before
 * validation; the channel's own Policy check in routes/channels.php then runs as well.
 * A new channel widens this check to "may view any listed channel".
 */
final class AuthorizeChannelRequest extends ApiFormRequest
{
    /** Pusher-protocol names, as the client sends them. */
    public const array CHANNELS = ['private-inbox'];

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Conversation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', Rule::in(self::CHANNELS)],
        ];
    }
}
