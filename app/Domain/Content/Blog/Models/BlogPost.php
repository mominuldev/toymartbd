<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Models;

use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A blog post or buying guide. Live on the storefront when it is not a draft and its
 * `published_at` (UTC) has been reached — the `live` scope. Written only by
 * {@see \App\Domain\Content\Blog\Actions\SaveBlogPost}; body HTML is sanitised there.
 *
 * @property int $id
 * @property PostType $type
 * @property string $slug
 * @property string $title
 * @property string|null $title_bn
 * @property string|null $excerpt
 * @property string|null $excerpt_bn
 * @property string|null $body
 * @property string|null $body_bn
 * @property int|null $cover_media_id
 * @property int|null $author_id
 * @property int|null $category_id
 * @property int $reading_minutes
 * @property int|null $reading_minutes_bn
 * @property PostStatus $status
 * @property Carbon|null $published_at
 * @property bool $is_featured
 * @property AgeBand|null $guide_age_band
 * @property Money|null $guide_budget_min
 * @property Money|null $guide_budget_max
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $cover
 * @property-read BlogAuthor|null $author
 * @property-read BlogCategory|null $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BlogTag> $tags
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BlogPostSection> $sections
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BlogPostProduct> $relatedProductLinks
 */
final class BlogPost extends Model
{
    /** `seo_meta.seoable_type` and `slug_history.sluggable_type` for posts. */
    public const string SEO_TYPE = 'blog_post';

    /** Generated slugs leave room for a "-NNN" suffix within the 94-char column (tag `content:post:{id}` is id-based). */
    public const int SLUG_BASE_MAX = 90;

    public const int SLUG_MAX = 94;

    protected $table = 'blog_posts';

    /** @var list<string> */
    protected $fillable = [
        'type', 'slug', 'title', 'title_bn', 'excerpt', 'excerpt_bn', 'body', 'body_bn', 'cover_media_id', 'author_id',
        'category_id', 'reading_minutes', 'reading_minutes_bn', 'status', 'published_at', 'is_featured', 'guide_age_band',
        'guide_budget_min', 'guide_budget_max', 'created_by', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'article',
        'status' => 'draft',
        'reading_minutes' => 1,
        'is_featured' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PostType::class,
            'status' => PostStatus::class,
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'reading_minutes' => 'integer',
            'reading_minutes_bn' => 'integer',
            'cover_media_id' => 'integer',
            'author_id' => 'integer',
            'category_id' => 'integer',
            'guide_age_band' => AgeBand::class,
            'guide_budget_min' => MoneyCast::class,
            'guide_budget_max' => MoneyCast::class,
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @return BelongsTo<BlogAuthor, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(BlogAuthor::class, 'author_id');
    }

    /** @return BelongsTo<BlogCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    /** @return BelongsToMany<BlogTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag', 'post_id', 'tag_id')->orderBy('blog_tags.name');
    }

    /** @return HasMany<BlogPostSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(BlogPostSection::class, 'post_id')->orderBy('position')->orderBy('id');
    }

    /**
     * Every linked product row: guide picks and manual related products.
     *
     * @return HasMany<BlogPostProduct, $this>
     */
    public function productLinks(): HasMany
    {
        return $this->hasMany(BlogPostProduct::class, 'post_id')->orderBy('position')->orderBy('id');
    }

    /**
     * Manual related products (no section).
     *
     * @return HasMany<BlogPostProduct, $this>
     */
    public function relatedProductLinks(): HasMany
    {
        return $this->productLinks()->whereNull('section_id');
    }

    /** Live on the storefront now (the `live` scope, for one row). */
    public function isLive(): bool
    {
        return $this->status !== PostStatus::Draft
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(Carbon::now());
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('blog_posts.status', '!=', PostStatus::Draft->value)
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', Carbon::now());
    }
}
