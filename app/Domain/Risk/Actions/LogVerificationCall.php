<?php

declare(strict_types=1);

namespace App\Domain\Risk\Actions;

use App\Domain\Risk\Enums\CallOutcome;
use App\Domain\Risk\Models\RiskVerificationCall;

/** Logs a verification call about a held order. Never moves the order: a person does that separately. */
final readonly class LogVerificationCall
{
    public function handle(int $orderId, string $e164, CallOutcome $outcome, ?string $note, ?int $userId): RiskVerificationCall
    {
        return RiskVerificationCall::query()->create([
            'order_id' => $orderId,
            'phone' => $e164,
            'outcome' => $outcome,
            'note' => $note,
            'user_id' => $userId,
        ]);
    }
}
