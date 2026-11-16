<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\CapitalHolderRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An owner or partner who puts money into the business or takes it out (P10 owner capital).
 * No share percentage and no profit allocation (owner decision 2026-11-16). Deactivated,
 * never deleted: a new entry needs an active holder, history keeps pointing at the row.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property CapitalHolderRole $role
 * @property int|null $user_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
final class CapitalHolder extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'name_bn', 'role', 'user_id', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'role' => 'owner'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CapitalEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(CapitalEntry::class, 'holder_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => CapitalHolderRole::class, 'user_id' => 'integer', 'is_active' => 'boolean'];
    }
}
