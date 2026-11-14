<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Division;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Inventory\Enums\StockLocationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A stock location (DATABASE-DESIGN §9): a warehouse, retail branch or pickup point.
 * The migration seeds the default one; `code` is the short label the inventory overview
 * shows for it ("CTG 4 · SYL 0"), `description` what it is for.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $description
 * @property string|null $description_bn
 * @property StockLocationType|null $type
 * @property int|null $division_id
 * @property int|null $district_id
 * @property int|null $upazila_id
 * @property string|null $area
 * @property string|null $address_line
 * @property string|null $landmark
 * @property string|null $notes
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockLocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_bn', 'description', 'description_bn', 'is_default', 'is_active',
        // Store settings › Branches & warehouses: type, address (rule 31) and the facts line.
        'type', 'division_id', 'district_id', 'upazila_id', 'area', 'address_line', 'landmark', 'notes',
    ];

    public static function defaultId(): int
    {
        $id = self::query()->where('is_default', true)->orderBy('id')->value('id');

        return $id === null ? throw new RuntimeException('No default stock location is seeded.') : (int) $id;
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'type' => StockLocationType::class];
    }
}
