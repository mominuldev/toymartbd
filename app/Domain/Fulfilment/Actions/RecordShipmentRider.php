<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Exceptions\RiderNotRecordable;
use App\Domain\Fulfilment\Models\Shipment;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Records (or clears) the delivery rider on a shipment — owner decision 2026-11-13: the "out
 * for delivery" message names the RIDER's phone, never our hotline. Neither Pathao's nor
 * Steadfast's official API returns the rider's number (and scraping the merchant portal is
 * forbidden, rule 24a), so staff type it in when the rider calls.
 *
 * Only a shipment a courier holds has a rider: pending, failed and cancelled are a 409. The
 * phone is stored E.164 (rule 30). Every change writes one `audit_sensitive` row with the
 * phone MASKED (rule 20) — before and after.
 *
 * Recording a rider never sends anything: if the "out for delivery" message already went
 * out (without the rider line), it is not resent.
 */
final readonly class RecordShipmentRider
{
    public const int NAME_MAX = 100;

    /** @throws RiderNotRecordable */
    public function handle(Shipment $shipment, ?string $name, ?PhoneNumber $phone, ?int $userId, string $source = Shipment::RIDER_MANUAL): Shipment
    {
        return DB::transaction(function () use ($shipment, $name, $phone, $userId, $source): Shipment {
            /** @var Shipment $locked */
            $locked = Shipment::query()->with('provider')->lockForUpdate()->findOrFail($shipment->id);
            if (in_array($locked->status, [ShipmentStatus::Pending, ShipmentStatus::Failed, ShipmentStatus::Cancelled], true)) {
                throw RiderNotRecordable::for($locked->number, $locked->status);
            }

            $name = $name === null || trim($name) === '' ? null : mb_substr(trim($name), 0, self::NAME_MAX);
            $e164 = $phone?->e164();

            if ($locked->rider_name === $name && $locked->rider_phone === $e164) {
                return $locked;
            }

            $before = self::audited($locked->rider_name, $locked->rider_phone);
            $cleared = $name === null && $e164 === null;

            $locked->forceFill([
                'rider_name' => $name,
                'rider_phone' => $e164,
                'rider_source' => $cleared ? null : $source,
                'rider_recorded_at' => $cleared ? null : now(),
                'rider_recorded_by' => $cleared ? null : $userId,
            ])->save();

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::ShipmentRiderRecorded,
                'model_type' => $locked->getMorphClass(),
                'model_id' => $locked->id,
                'before' => $before,
                'after' => self::audited($name, $e164) + ['source' => $cleared ? null : $source],
            ]);

            return $locked;
        });
    }

    /** @return array{name: string|null, phone: string|null} the phone masked — never in full (rule 20) */
    private static function audited(?string $name, ?string $e164): array
    {
        return [
            'name' => $name,
            'phone' => $e164 === null ? null : (PhoneNumber::tryParse($e164)?->masked() ?? '***'),
        ];
    }
}
