<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An upazila (sub-district) — static reference data (DATABASE-DESIGN §3).
 *
 * The two zone flags feed the delivery-charge tier (§5.1). They are provisional — see
 * database/data/geo/SOURCE.md — and say nothing about any courier's zones (rule 32).
 * Note that Dhaka's city corporations are not upazilas, so no row here is the city itself.
 *
 * @property int $id
 * @property int $district_id
 * @property string $name
 * @property string $name_bn
 * @property string|null $pcode
 * @property bool $is_inside_dhaka_metro
 * @property bool $is_dhaka_subcity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Upazila extends Model
{
    /** @var list<string> */
    protected $fillable = ['id', 'district_id', 'name', 'name_bn', 'pcode', 'is_inside_dhaka_metro', 'is_dhaka_subcity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_inside_dhaka_metro' => 'boolean',
            'is_dhaka_subcity' => 'boolean',
        ];
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
