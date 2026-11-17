<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * My account → Password. The FormRequest has already checked the current password. API
 * tokens and the remember-me token are revoked; Sanctum's AuthenticateSession signs out every
 * other browser session (their stored password hash no longer matches) and re-stamps this one,
 * so the person stays signed in here. Audited as `credential_changed`, never with the value.
 */
final readonly class ChangeOwnPassword
{
    public function handle(User $user, #[SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();
            $user->tokens()->delete();

            AuditSensitive::query()->create([
                'user_id' => $user->getKey(),
                'action' => AuditAction::CredentialChanged,
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->getKey(),
                'before' => ['scope' => 'own_password'],
                'after' => ['scope' => 'own_password', 'password' => 'changed'],
            ]);
        });
    }
}
