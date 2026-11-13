<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Enums\BannerPlacement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A storefront banner in one placement slot. Schedules are UTC instants; the admin enters
 * and reads them in Dhaka time. Live = active and inside [starts_at, ends_at).
 *
 * @property int $id
 * @property BannerPlacement $placement
 * @property string $name
 * @property string|null $title
 * @property string|null $title_bn
 * @property string|null $body
 * @property string|null $body_bn
 * @property int|null $media_id
 * @property string|null $link_url
 * @property string|null $link_label
 * @property string|null $link_label_bn
 * @property int|null $category_id
 * @property int $priority
 * @property bool $is_active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $media
 * @property-read Category|null $category
 */
final class Banner extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'placement', 'name', 'title', 'title_bn', 'body', 'body_bn', 'media_id', 'link_url', 'link_label', 'link_label_bn',
        'category_id', 'priority', 'is_active', 'starts_at', 'ends_at', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['priority' => 0, 'is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'placement' => BannerPlacement::class,
            'media_id' => 'integer',
            'category_id' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** `scheduled` (starts later), `live`, `ended`, or `inactive` (switched off). */
    public function scheduleState(?CarbonInterface $at = null): string
    {
        $at ??= now();

        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_at !== null && $this->starts_at->greaterThan($at) => 'scheduled',
            $this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($at) => 'ended',
            default => 'live',
        };
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function liveAt(Builder $query, CarbonInterface $at): void
    {
        $query->where('is_active', true)
            ->where(static fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(static fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
