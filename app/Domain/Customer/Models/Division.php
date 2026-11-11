<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * One of Bangladesh's eight divisions — static reference data (DATABASE-DESIGN §3,
 * source in database/data/geo/SOURCE.md). Addresses are division → district → upazila.
 *
 * @property int $id
 * @property string $name
 * @property string $name_bn
 * @property string|null $pcode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Division extends Model
{
    /** @var list<string> */
    protected $fillable = ['id', 'name', 'name_bn', 'pcode'];

    /** @return HasMany<District, $this> */
    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    /** @return HasManyThrough<Upazila, District, $this> */
    public function upazilas(): HasManyThrough
    {
        return $this->hasManyThrough(Upazila::class, District::class);
    }
}
