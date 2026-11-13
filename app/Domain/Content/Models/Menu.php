<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\MenuLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One storefront menu per location. Only a published menu reaches the storefront; a location
 * without one falls back to the category-tree menu.
 *
 * @property int $id
 * @property MenuLocation $location
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MenuItem> $items
 */
final class Menu extends Model
{
    /** @var list<string> */
    protected $fillable = ['location', 'is_published', 'published_at', 'updated_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_published' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'location' => MenuLocation::class,
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'updated_by' => 'integer',
        ];
    }

    /** @return HasMany<MenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('position')->orderBy('id');
    }
}
