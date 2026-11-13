<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Models;

use App\Domain\Catalog\Enums\AgeBand;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One section of a buying guide: heading, sanitised intro, optional age band and budget,
 * and its product picks in order.
 *
 * @property int $id
 * @property int $post_id
 * @property int $position
 * @property string $heading
 * @property string|null $heading_bn
 * @property string|null $body
 * @property string|null $body_bn
 * @property AgeBand|null $age_band
 * @property Money|null $budget_min
 * @property Money|null $budget_max
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BlogPostProduct> $picks
 */
final class BlogPostSection extends Model
{
    protected $table = 'blog_post_sections';

    /** @var list<string> */
    protected $fillable = ['post_id', 'position', 'heading', 'heading_bn', 'body', 'body_bn', 'age_band', 'budget_min', 'budget_max'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'post_id' => 'integer',
            'position' => 'integer',
            'age_band' => AgeBand::class,
            'budget_min' => MoneyCast::class,
            'budget_max' => MoneyCast::class,
        ];
    }

    /** @return BelongsTo<BlogPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'post_id');
    }

    /** @return HasMany<BlogPostProduct, $this> */
    public function picks(): HasMany
    {
        return $this->hasMany(BlogPostProduct::class, 'section_id')->orderBy('position')->orderBy('id');
    }
}
