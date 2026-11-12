<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Customer\Enums\AddressType;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved delivery address (DATABASE-DESIGN §3.3): division → district → upazila → area +
 * line + landmark (rule 31). Orders snapshot it rather than reference it.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $label
 * @property string $recipient_name
 * @property string $phone
 * @property string|null $alt_phone
 * @property int $division_id
 * @property int $district_id
 * @property int|null $upazila_id
 * @property string|null $area
 * @property string $address_line
 * @property string|null $landmark
 * @property string|null $postcode
 * @property AddressType $type
 * @property bool $is_default_shipping
 * @property bool $is_default_billing
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Division $division
 * @property-read District $district
 * @property-read Upazila|null $upazila
 */
final class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'customer_id', 'label', 'recipient_name', 'phone', 'alt_phone', 'division_id', 'district_id',
        'upazila_id', 'area', 'address_line', 'landmark', 'postcode', 'type', 'is_default_shipping',
        'is_default_billing', 'lat', 'lng',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'division_id' => 'integer',
            'district_id' => 'integer',
            'upazila_id' => 'integer',
            'type' => AddressType::class,
            'is_default_shipping' => 'boolean',
            'is_default_billing' => 'boolean',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<Upazila, $this> */
    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class);
    }

    protected static function newFactory(): AddressFactory
    {
        return AddressFactory::new();
    }
}
