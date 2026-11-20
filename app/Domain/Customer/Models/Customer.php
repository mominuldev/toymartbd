<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use App\Support\PhoneNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A shopper, identified by phone (DATABASE-DESIGN §1.4, §3.3). Most never register:
 * guest orders attach here by phone. `phone` is stored E.164 (rule 30); show it through
 * {@see maskedPhone()} unless the viewer holds customers.view_pii (rule 20).
 *
 * The aggregate columns are a projection of `orders` (P4-ORD-10), written only by
 * {@see \App\Domain\Customer\Services\CustomerAggregates}.
 *
 * Authenticatable for the `customer` guard (P4-STO-11): a storefront account signs in with
 * phone + password or phone + SMS code (owner decision 2026-11-20) and holds a Sanctum token.
 * `password` is hashed (the `hashed` cast) and hidden; it is null for guest-checkout customers
 * and anyone who has not set one yet — the storefront sends a signed-in customer without one to
 * "Set your password" before the account pages. Registered = has a password or has signed in
 * ({@see isRegistered()}); a guest-checkout row is not, so its owner can still register.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $password Hashed; never logged, never serialised.
 * @property Carbon|null $password_set_at
 * @property string|null $email
 * @property Carbon|null $date_of_birth Personal data: shown to staff only with customers.view_pii.
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
final class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'name', 'phone', 'phone_verified_at', 'email', 'email_verified_at', 'date_of_birth',
        'gender', 'preferred_language', 'source', 'tags', 'status',
    ];

    /** @var list<string> */
    protected $hidden = ['password'];

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
            'password' => 'hashed',
            'password_set_at' => 'datetime',
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

    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /**
     * Has an account, as opposed to a guest-checkout row: a password is set, or the phone was
     * proved by a sign-in code. "Create an account" refuses a registered phone (409).
     */
    public function isRegistered(): bool
    {
        return $this->hasPassword() || $this->phone_verified_at !== null;
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

    /** @return HasMany<CustomerChild, $this> Children's data (P8 batch 3): customers.view_pii only. */
    public function children(): HasMany
    {
        return $this->hasMany(CustomerChild::class)->orderBy('birth_month')->orderBy('birth_day')->orderBy('id');
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** No remember-me: customers authenticate by bearer token only. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
