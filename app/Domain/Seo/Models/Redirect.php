<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A manual redirect (DATABASE-DESIGN §3.9 `redirects`, admin Redirect manager).
 * Locale-independent paths. Written only through the Seo Actions, which keep the
 * table free of loops and chains.
 *
 * @property int $id
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property int $hit_count
 * @property Carbon|null $last_hit_at
 * @property bool $is_active
 * @property string $source manual|import
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Redirect extends Model
{
    public const string SOURCE_MANUAL = 'manual';

    public const string SOURCE_IMPORT = 'import';

    /** @var list<string> */
    protected $fillable = ['from_path', 'to_path', 'status_code', 'is_active', 'source', 'note', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status_code' => 301, 'hit_count' => 0, 'is_active' => true, 'source' => 'manual'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hit_count' => 'integer',
            'last_hit_at' => 'datetime',
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }
}
