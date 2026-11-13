<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\MessageConsentPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A customer's consent to one purpose of message (P8): today only `birthday`. No row means
 * NOT consented. Provenance: who recorded it (`recorded_by`, null = the customer on the
 * storefront), from where, when given and when withdrawn.
 *
 * @property int $id
 * @property int $customer_id
 * @property MessageConsentPurpose $purpose
 * @property bool $consented
 * @property Carbon|null $consented_at
 * @property Carbon|null $withdrawn_at
 * @property string $source
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MessageConsent extends Model
{
    protected $table = 'customer_message_consents';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'purpose' => MessageConsentPurpose::class,
            'consented' => 'boolean',
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'recorded_by' => 'integer',
        ];
    }

    public static function given(int $customerId, MessageConsentPurpose $purpose): bool
    {
        return self::query()->where('customer_id', $customerId)->where('purpose', $purpose->value)->where('consented', true)->exists();
    }
}
