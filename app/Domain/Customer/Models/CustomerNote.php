<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A staff note on a customer (DATABASE-DESIGN §3.3). Append-only: the model refuses update
 * and delete, so author + time are a reliable audit trail. Written only by
 * {@see \App\Domain\Customer\Actions\AddCustomerNote}. The body is never logged (it may
 * hold a phone number or an address, rule 20).
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $is_pinned
 * @property Carbon|null $created_at
 */
final class CustomerNote extends Model
{
    public const null UPDATED_AT = null;

    public const int MAX_LENGTH = 2000;

    /** @var list<string> */
    protected $fillable = ['customer_id', 'user_id', 'body', 'is_pinned'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Customer notes are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Customer notes are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'user_id' => 'integer',
            'is_pinned' => 'boolean',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
