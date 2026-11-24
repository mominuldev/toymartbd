<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Models;

use App\Domain\Content\Blocks\Enums\VideoLengthStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A reel video's detected length, keyed by its link (`url_hash` = sha256 of the link).
 *
 * @property int $id
 * @property string $url_hash
 * @property string $url
 * @property string $provider
 * @property int|null $seconds
 * @property VideoLengthStatus $status
 * @property int $attempts
 * @property Carbon|null $checked_at
 */
final class VideoLength extends Model
{
    protected $fillable = ['url_hash', 'url', 'provider', 'seconds', 'status', 'attempts', 'checked_at'];

    protected function casts(): array
    {
        return [
            'seconds' => 'integer',
            'status' => VideoLengthStatus::class,
            'attempts' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public static function hashOf(string $url): string
    {
        return hash('sha256', $url);
    }
}
