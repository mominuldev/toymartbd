<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Models;

use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A courier and its stored rate card (DATABASE-DESIGN §3.6). Credentials and the
 * webhook secret are encrypted at rest and hidden from serialisation (rule 20).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_bn
 * @property bool $is_active
 * @property bool $is_default
 * @property bool $verified
 * @property bool $requires_location_mapping
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property bool $supports_bulk
 * @property bool $supports_webhook
 * @property string|null $webhook_secret
 * @property Money $base_charge_dhaka
 * @property Money $base_charge_suburb
 * @property Money $base_charge_outside
 * @property int $included_weight_grams
 * @property Money $per_kg_charge
 * @property string $cod_fee_percent
 * @property Money $return_charge
 * @property Money|null $balance
 * @property Carbon|null $balance_checked_at
 * @property int $position
 * @property Carbon|null $rate_card_updated_at
 * @property Carbon|null $credentials_updated_at
 */
final class CourierProvider extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_bn', 'is_active', 'is_default', 'verified', 'requires_location_mapping',
        'credentials', 'settings', 'supports_bulk', 'supports_webhook', 'webhook_secret',
        'base_charge_dhaka', 'base_charge_suburb', 'base_charge_outside', 'included_weight_grams',
        'per_kg_charge', 'cod_fee_percent', 'return_charge', 'position',
    ];

    /** @var list<string> */
    protected $hidden = ['credentials', 'webhook_secret'];

    /** The active default provider, whose rate card prices the storefront quote (§5.1 step 3). */
    public static function defaultForQuotes(): ?self
    {
        return self::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('position')->orderBy('id')->first();
    }

    public function baseChargeFor(DeliveryZone $zone): Money
    {
        return match ($zone) {
            DeliveryZone::InsideDhakaMetro => $this->base_charge_dhaka,
            DeliveryZone::DhakaSuburb => $this->base_charge_suburb,
            DeliveryZone::OutsideDhaka => $this->base_charge_outside,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'verified' => 'boolean',
            'requires_location_mapping' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'supports_bulk' => 'boolean',
            'supports_webhook' => 'boolean',
            'webhook_secret' => 'encrypted',
            'base_charge_dhaka' => MoneyCast::class,
            'base_charge_suburb' => MoneyCast::class,
            'base_charge_outside' => MoneyCast::class,
            'included_weight_grams' => 'integer',
            'per_kg_charge' => MoneyCast::class,
            'return_charge' => MoneyCast::class,
            'balance' => MoneyCast::class,
            'balance_checked_at' => 'datetime',
            'position' => 'integer',
            'rate_card_updated_at' => 'datetime',
            'credentials_updated_at' => 'datetime',
        ];
    }
}
