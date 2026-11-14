<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inbox;

use App\Domain\Inbox\Models\Conversation;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use App\Models\User;
use Closure;

/**
 * `POST /inbox/conversations/{conversation}/assign` — needs inbox.reply. `user_id` null
 * unassigns; otherwise an ACTIVE staff member who holds inbox.reply (GET /inbox/assignees).
 */
final class AssignConversationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && ($this->user()?->can('assign', $conversation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['present', 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null) {
                    return;
                }
                $user = User::query()->find($value);
                if (! $user instanceof User || ! $user->is_active || ! $user->checkPermissionTo(Permission::InboxReply->value)) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            }],
        ];
    }

    public function assignee(): ?User
    {
        $id = $this->input('user_id');

        return $id === null ? null : User::query()->find((int) $id);
    }
}
