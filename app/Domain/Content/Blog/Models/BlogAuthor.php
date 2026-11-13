<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Models;

use App\Domain\Catalog\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A blog byline (EN + BN name, bio, avatar). Deleting one with posts is refused.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property string|null $bio
 * @property string|null $bio_bn
 * @property int|null $avatar_media_id
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $avatar
 */
final class BlogAuthor extends Model
{
    public const string SEO_TYPE = 'blog_author';

    protected $table = 'blog_authors';

    /** @var list<string> */
    protected $fillable = ['name', 'name_bn', 'slug', 'bio', 'bio_bn', 'avatar_media_id', 'user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['avatar_media_id' => 'integer', 'user_id' => 'integer'];
    }

    /** @return BelongsTo<Media, $this> */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    /** @return HasMany<BlogPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'author_id');
    }
}
