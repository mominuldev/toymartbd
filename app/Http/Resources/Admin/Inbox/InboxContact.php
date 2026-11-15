<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Inbox;

use App\Domain\Customer\Support\CustomerPhoneMask;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Services\ReplyWindow;
use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who the conversation is with, as one viewer may see it. The phone is masked
 * (`017****5678`) unless the viewer holds customers.view_pii (rule 20).
 */
final class InboxContact
{
    /** @return array{name: string|null, phone_masked: string|null, phone: string|null} */
    public static function for(Conversation $conversation, Request $request): array
    {
        $phone = $conversation->contact_phone ?? $conversation->customer?->phone;
        $user = $request->user();
        $pii = $user instanceof User && $user->checkPermissionTo(Permission::CustomersViewPii->value);

        return [
            'name' => $conversation->contact_name ?? $conversation->customer?->name,
            'phone_masked' => $phone === null ? null : CustomerPhoneMask::mask($phone),
            'phone' => $pii ? $phone : null,
        ];
    }

    /** @return array{state: string, window_closes_at: string|null} */
    public static function reply(Conversation $conversation, ReplyWindow $window): array
    {
        $allowance = $window->for($conversation);

        return ['state' => $allowance->state->value, 'window_closes_at' => $allowance->closesAt?->toIso8601String()];
    }

    /** @return array{applies: bool, open: bool, expires_at: string|null} */
    public static function window(Conversation $conversation): array
    {
        return [
            'applies' => $conversation->channel->hasServiceWindow(),
            'open' => $conversation->windowOpen(),
            'expires_at' => $conversation->windowExpiresAt()?->toIso8601String(),
        ];
    }
}
