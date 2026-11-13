<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

use App\Domain\Seo\Enums\RobotsDirective;
use App\Domain\Seo\Enums\TwitterCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Search and social overrides for one page (DATABASE-DESIGN §3.9 `seo_meta`).
 * Polymorphic by a short type ("product"), not a class name, so a model rename never
 * orphans rows. Every text column is optional; an empty one falls back to the page's
 * server-side default. Written only through {@see \App\Domain\Seo\Actions\SaveProductWithSeo}
 * (with the product document) and {@see \App\Domain\Seo\Actions\SaveSeoMeta} (every type).
 *
 * @property int $id
 * @property string $seoable_type
 * @property int $seoable_id
 * @property string|null $title
 * @property string|null $title_bn
 * @property string|null $description
 * @property string|null $description_bn
 * @property string|null $canonical_url
 * @property RobotsDirective $robots
 * @property bool $sitemap_exclude
 * @property string|null $og_title
 * @property string|null $og_description
 * @property string|null $og_title_bn
 * @property string|null $og_description_bn
 * @property string|null $og_image
 * @property TwitterCard $twitter_card
 * @property array<string, mixed>|null $schema_override
 * @property string|null $focus_keyword
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SeoMeta extends Model
{
    public const string TYPE_PRODUCT = 'product';

    protected $table = 'seo_meta';

    /** @var list<string> */
    protected $fillable = [
        'seoable_type', 'seoable_id', 'title', 'title_bn', 'description', 'description_bn', 'canonical_url', 'robots',
        'sitemap_exclude', 'og_title', 'og_description', 'og_title_bn', 'og_description_bn', 'og_image', 'twitter_card', 'schema_override', 'focus_keyword',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'robots' => 'index,follow',
        'sitemap_exclude' => false,
        'twitter_card' => 'summary_large_image',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seoable_id' => 'integer',
            'robots' => RobotsDirective::class,
            'twitter_card' => TwitterCard::class,
            'sitemap_exclude' => 'boolean',
            'schema_override' => 'array',
        ];
    }

    /** @return Builder<self> */
    public static function for(string $type, int $id): Builder
    {
        return self::query()->where('seoable_type', $type)->where('seoable_id', $id);
    }

    /** @return Builder<self> */
    public static function forProduct(int $productId): Builder
    {
        return self::query()->where('seoable_type', self::TYPE_PRODUCT)->where('seoable_id', $productId);
    }
}
