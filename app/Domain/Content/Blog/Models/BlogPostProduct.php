<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product linked from a post: a guide pick (`section_id` set) or a manual related
 * product (`section_id` null). Hidden on read when the product is not buyable.
 *
 * @property int $id
 * @property int $post_id
 * @property int|null $section_id
 * @property int $product_id
 * @property int $position
 * @property string|null $note
 * @property string|null $note_bn
 */
final class BlogPostProduct extends Model
{
    public $timestamps = false;

    protected $table = 'blog_post_products';

    /** @var list<string> */
    protected $fillable = ['post_id', 'section_id', 'product_id', 'position', 'note', 'note_bn'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['post_id' => 'integer', 'section_id' => 'integer', 'product_id' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<BlogPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'post_id');
    }
}
