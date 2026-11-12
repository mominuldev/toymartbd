<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One retired slug (DATABASE-DESIGN §3.9 `slug_history`): a request for `old_slug` is to
 * 301 to the page's current URL. Insert-only from the application; the Phase 7
 * middleware reads it.
 *
 * @property int $id
 * @property string $sluggable_type
 * @property int $sluggable_id
 * @property string $old_slug
 * @property string $new_slug
 * @property int|null $user_id
 * @property Carbon|null $created_at
 */
final class SlugHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'slug_history';

    /** @var list<string> */
    protected $fillable = ['sluggable_type', 'sluggable_id', 'old_slug', 'new_slug', 'user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sluggable_id' => 'integer', 'user_id' => 'integer'];
    }
}
