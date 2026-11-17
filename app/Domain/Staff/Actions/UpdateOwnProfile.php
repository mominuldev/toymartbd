<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * My account → Profile: the signed-in staff member renames themselves and/or changes their
 * sign-in email. The FormRequest has already checked the current password for an email
 * change. An email change is audited as `credential_changed` with the word "changed" only.
 * Roles and active state are never touched here — those stay in Staff & roles.
 */
final readonly class UpdateOwnProfile
{
    public function handle(User $user, string $name, string $email): User
    {
        return DB::transaction(function () use ($user, $name, $email): User {
            $emailChanged = mb_strtolower($email) !== mb_strtolower($user->email);

            $user->forceFill(['name' => $name, 'email' => $email])->save();

            if ($emailChanged) {
                AuditSensitive::query()->create([
                    'user_id' => $user->getKey(),
                    'action' => AuditAction::CredentialChanged,
                    'model_type' => $user->getMorphClass(),
                    'model_id' => $user->getKey(),
                    'before' => ['scope' => 'own_email'],
                    'after' => ['scope' => 'own_email', 'email' => 'changed'],
                ]);
            }

            return $user->refresh();
        });
    }
}
