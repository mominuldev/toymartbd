<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\MessageConsentPurpose;
use App\Domain\Notification\Models\MessageConsent;
use Illuminate\Support\Facades\DB;

/**
 * Records a customer's consent to (or withdrawal from) one purpose of message (P8) — today
 * birthday messages. Keeps provenance: when it was given, when withdrawn, from where, by whom.
 * A withdrawal updates the row (never deletes it); `consented_at` keeps when it was last given.
 */
final readonly class SetMessageConsent
{
    public function handle(int $customerId, MessageConsentPurpose $purpose, bool $consented, string $source, ?int $userId): MessageConsent
    {
        return DB::transaction(static function () use ($customerId, $purpose, $consented, $source, $userId): MessageConsent {
            $row = MessageConsent::query()->where('customer_id', $customerId)->where('purpose', $purpose->value)->lockForUpdate()->first()
                ?? new MessageConsent(['customer_id' => $customerId, 'purpose' => $purpose, 'consented' => false]);

            // Unchanged is a no-op. "No" with no row is also a no-op: no row already means not
            // consented, and a withdrawal stamp on something never given would be false provenance.
            if ($row->consented === $consented) {
                return $row;
            }

            $row->fill([
                'consented' => $consented,
                'source' => $source,
                'recorded_by' => $userId,
            ]);
            if ($consented) {
                $row->consented_at = now();
                $row->withdrawn_at = null;
            } else {
                $row->withdrawn_at = now();
            }
            $row->save();

            return $row;
        });
    }
}
