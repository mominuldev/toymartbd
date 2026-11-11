<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\CategoryStatus;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Kalnoy\Nestedset\NodeTrait;
use Kalnoy\Nestedset\QueryBuilder;

/**
 * A catalog category (DATABASE-DESIGN §3.1), stored as a nested set so the mega
 * menu and breadcrumbs are one indexed range query. Archived, never hard-deleted:
 * products hold `primary_category_id`. The slug is ASCII and shared by both
 * locales (rule 37).
 *
 * Sibling order is the nested-set order itself (`_lft`), changed only through
 * MoveCategory. `position` is kept in step with it for readers that only see one
 * level.
 *
 * TODO(P4-SEO): write `slug_history` on a slug change, as for brands.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property int $_lft
 * @property int $_rgt
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property string|null $description
 * @property string|null $description_bn
 * @property string|null $image_path
 * @property string|null $icon
 * @property string|null $banner_path
 * @property int $position
 * @property bool $is_featured
 * @property bool $show_in_menu
 * @property CategoryStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use NodeTrait;
    use SoftDeletes;

    /** Generated slugs leave room for a "-NNN" uniqueness suffix within the 91-char column ("category:" + 91 = the 100-char tag limit). */
    public const int SLUG_BASE_MAX = 87;

    public const int SLUG_MAX = 91;

    /** Path segments the storefront API uses beside `/categories/{slug}`. */
    public const array RESERVED_SLUGS = ['tree'];

    /** The storefront menu and the admin both stop at three levels (root = depth 0). */
    public const int MAX_DEPTH = 2;

    /** @var list<string> */
    protected $fillable = [
        'name', 'name_bn', 'slug', 'description', 'description_bn', 'image_path', 'icon', 'banner_path',
        'position', 'is_featured', 'show_in_menu', 'status',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'position' => 0,
        'is_featured' => false,
        'show_in_menu' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CategoryStatus::class,
            'position' => 'integer',
            'is_featured' => 'boolean',
            'show_in_menu' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === CategoryStatus::Active;
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', CategoryStatus::Active->value);
    }

    /**
     * Tree order (`_lft`). The nested-set scopes below wrap kalnoy's builder
     * methods: Larastan types Category::query() as a plain Builder, so each one
     * narrows to kalnoy's QueryBuilder before calling it.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inTreeOrder(Builder $query): void
    {
        if ($query instanceof QueryBuilder) {
            $query->defaultOrder();
        }
    }

    /**
     * Adds a computed `depth` column (root = 0).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withTreeDepth(Builder $query): void
    {
        if ($query instanceof QueryBuilder) {
            $query->withDepth();
        }
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function treeAncestorsOf(Builder $query, self $category): void
    {
        if ($query instanceof QueryBuilder) {
            $query->whereAncestorOf($category);
        }
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function treeDescendantsOf(Builder $query, self $category): void
    {
        if ($query instanceof QueryBuilder) {
            $query->whereDescendantOf($category);
        }
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
