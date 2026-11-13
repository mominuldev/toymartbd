<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A blog tag. Deleting one detaches it from its posts.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BlogTag extends Model
{
    public const string SEO_TYPE = 'blog_tag';

    protected $table = 'blog_tags';

    /** @var list<string> */
    protected $fillable = ['name', 'name_bn', 'slug'];

    /** @return BelongsToMany<BlogPost, $this> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_tag', 'tag_id', 'post_id');
    }
}
