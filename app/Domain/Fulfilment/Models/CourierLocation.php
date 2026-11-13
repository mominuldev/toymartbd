<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Models;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\DTOs\CourierLocationRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One node of a courier's own location tree — a zone (`external_area_id` NULL) or an area —
 * and its explicit mapping to our geography (DATABASE-DESIGN §3.6). The external ids are
 * the provider's namespace and are never derived from our district/upazila names (rule 32).
 *
 * @property int $id
 * @property int $provider_id
 * @property string $external_key
 * @property string $external_city_id
 * @property string $external_city_name
 * @property string $external_zone_id
 * @property string $external_zone_name
 * @property string|null $external_area_id
 * @property string|null $external_area_name
 * @property bool|null $home_delivery_available
 * @property bool|null $pickup_available
 * @property int|null $district_id
 * @property int|null $upazila_id
 * @property bool $is_mapped
 * @property int|null $mapped_by
 * @property Carbon|null $mapped_at
 * @property bool $is_active
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CourierProvider $provider
 * @property-read District|null $district
 * @property-read Upazila|null $upazila
 */
final class CourierLocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'provider_id', 'external_key', 'external_city_id', 'external_city_name', 'external_zone_id',
        'external_zone_name', 'external_area_id', 'external_area_name', 'home_delivery_available',
        'pickup_available', 'district_id', 'upazila_id', 'is_mapped', 'mapped_by', 'mapped_at',
        'is_active', 'synced_at',
    ];

    public static function keyFor(string $cityId, string $zoneId, ?string $areaId): string
    {
        return $cityId.':'.$zoneId.':'.($areaId ?? '-');
    }

    public function isZone(): bool
    {
        return $this->external_area_id === null;
    }

    /** The provider ids a booking sends — only meaningful once a human has mapped the row. */
    public function ref(): CourierLocationRef
    {
        return new CourierLocationRef($this->external_city_id, $this->external_zone_id, $this->external_area_id);
    }

    /** @return BelongsTo<CourierProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'provider_id');
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

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMapped(Builder $query): Builder
    {
        return $query->where('is_mapped', true)->where('is_active', true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'home_delivery_available' => 'boolean',
            'pickup_available' => 'boolean',
            'is_mapped' => 'boolean',
            'mapped_at' => 'datetime',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
