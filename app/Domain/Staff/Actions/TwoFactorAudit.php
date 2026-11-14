<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;

/**
 * One `credential_changed` audit row for a two-factor event. Records only what happened —
 * never a secret, a code or a recovery code (rule 20). Called inside the caller's transaction.
 */
final class TwoFactorAudit
{
    public static function record(User $subject, ?int $actorId, string $what): void
    {
        AuditSensitive::query()->create([
            'user_id' => $actorId,
            'action' => AuditAction::CredentialChanged,
            'model_type' => $subject->getMorphClass(),
            'model_id' => $subject->getKey(),
            'before' => ['scope' => 'two_factor'],
            'after' => ['scope' => 'two_factor', 'two_factor' => $what],
        ]);
    }
}
