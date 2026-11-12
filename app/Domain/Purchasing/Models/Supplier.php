<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Purchasing\Enums\SupplierStatus;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A supplier (DATABASE-DESIGN §3.2). Archived, never deleted. `phone`/`alt_phone` are
 * E.164 (rule 30). The payable balance is computed by SupplierBalances, not stored.
 *
 * @property int $id
 * @property string $name
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $alt_phone
 * @property string|null $email
 * @property string|null $address
 * @property int|null $district_id
 * @property string|null $tin
 * @property int $payment_terms_days
 * @property Money $opening_balance
 * @property SupplierStatus $status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name', 'contact_person', 'phone', 'alt_phone', 'email', 'address', 'district_id', 'tin',
        'payment_terms_days', 'opening_balance', 'status', 'note',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'payment_terms_days' => 0,
        'opening_balance' => '0.00',
    ];

    public function isActive(): bool
    {
        return $this->status === SupplierStatus::Active;
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'district_id' => 'integer',
            'payment_terms_days' => 'integer',
            'opening_balance' => MoneyCast::class,
            'status' => SupplierStatus::class,
        ];
    }
}
