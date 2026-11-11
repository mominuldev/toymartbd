<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One of the 64 districts (zila) — static reference data (DATABASE-DESIGN §3).
 *
 * Never match a courier city to a district by `name` (CLAUDE.md rule 32): spellings
 * differ (Comilla/Cumilla) and the namespaces are unrelated. Use `courier_locations`.
 *
 * @property int $id
 * @property int $division_id
 * @property string $name
 * @property string $name_bn
 * @property string|null $pcode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class District extends Model
{
    /** @var list<string> */
    protected $fillable = ['id', 'division_id', 'name', 'name_bn', 'pcode'];

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** @return HasMany<Upazila, $this> */
    public function upazilas(): HasMany
    {
        return $this->hasMany(Upazila::class);
    }
}
