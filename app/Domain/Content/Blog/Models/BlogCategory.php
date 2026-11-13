<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A blog category (flat; one per post). Deleting one with posts is refused.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property string|null $description
 * @property string|null $description_bn
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BlogCategory extends Model
{
    public const string SEO_TYPE = 'blog_category';

    protected $table = 'blog_categories';

    /** @var list<string> */
    protected $fillable = ['name', 'name_bn', 'slug', 'description', 'description_bn', 'position'];

    /** @var array<string, mixed> */
    protected $attributes = ['position' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return HasMany<BlogPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'category_id');
    }
}
