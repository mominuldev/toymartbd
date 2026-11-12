<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A flat media-library folder (docs/api/media-library.md, decision 1). System folders
 * carry a `system_key` and cannot be renamed or deleted; `name_key` is the lower-cased
 * name that makes names unique case-insensitively.
 *
 * @property int $id
 * @property string $name
 * @property string $name_key
 * @property string|null $system_key
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MediaFolder extends Model
{
    public const string PRODUCTS = 'products';

    public const string CATEGORY_BANNERS = 'category_banners';

    public const string BLOG = 'blog';

    public const string PAGES = 'pages';

    /** system_key → [English default name, position]. */
    public const array SYSTEM = [
        self::PRODUCTS => ['Products', 0],
        self::CATEGORY_BANNERS => ['Category banners', 1],
        self::BLOG => ['Blog', 2],
        self::PAGES => ['Pages', 3],
    ];

    public const int CUSTOM_POSITION = 100;

    /** @var list<string> */
    protected $fillable = ['name', 'name_key', 'system_key', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /** @return HasMany<Media, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name')->orderBy('id');
    }

    public static function systemId(string $key): ?int
    {
        $id = self::query()->where('system_key', $key)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    public static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
