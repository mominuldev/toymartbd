<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use App\Support\PhoneNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A shopper, identified by phone (DATABASE-DESIGN §1.4, §3.3). Most never register:
 * guest orders attach here by phone. `phone` is stored E.164 (rule 30); show it through
 * {@see maskedPhone()} unless the viewer holds customers.view_pii (rule 20).
 *
 * The aggregate columns are a projection of `orders` (P4-ORD-10), written only by
 * {@see \App\Domain\Customer\Services\CustomerAggregates}.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $email
 * @property string $preferred_language
 * @property CustomerSource $source
 * @property list<string>|null $tags
 * @property CustomerStatus $status
 * @property Carbon|null $first_order_at
 * @property Carbon|null $last_order_at
 * @property int $orders_count
 * @property int $delivered_orders_count
 * @property Money $total_spent
 * @property Money $avg_order_value
 * @property Carbon|null $aggregates_refreshed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'name', 'phone', 'phone_verified_at', 'email', 'email_verified_at', 'date_of_birth',
        'gender', 'preferred_language', 'source', 'tags', 'status',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'preferred_language' => 'bn',
        'source' => 'web',
        'status' => 'active',
        'orders_count' => 0,
        'delivered_orders_count' => 0,
        'total_spent' => '0.00',
        'avg_order_value' => '0.00',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'source' => CustomerSource::class,
            'tags' => 'array',
            'status' => CustomerStatus::class,
            'first_order_at' => 'datetime',
            'last_order_at' => 'datetime',
            'orders_count' => 'integer',
            'delivered_orders_count' => 'integer',
            'total_spent' => MoneyCast::class,
            'avg_order_value' => MoneyCast::class,
            'aggregates_refreshed_at' => 'datetime',
        ];
    }

    /** Find by any accepted spelling of the phone (rule 30 normalisation). */
    public static function findByPhone(string $phone): ?self
    {
        $number = PhoneNumber::tryParse($phone);

        return $number === null ? null : self::query()->where('phone', $number->e164())->first();
    }

    /** 017****5678 — the only form logs and unprivileged views may show (rule 20). */
    public function maskedPhone(): string
    {
        return PhoneNumber::tryParse($this->phone)?->masked() ?? '***';
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
