<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Models;

use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A published version of a placement's block list (cms_layout_versions). Immutable: created by
 * PublishLayout / RevertLayout, never updated or deleted. The live page is the highest version.
 *
 * @property int $id
 * @property BlockPlacement $placement
 * @property int $version
 * @property list<array<string, mixed>> $blocks
 * @property int $block_count
 * @property string $content_hash
 * @property int|null $restored_from_version
 * @property string|null $note
 * @property int|null $published_by
 * @property CarbonImmutable $published_at
 * @property-read User|null $publisher
 */
final class CmsLayoutVersion extends Model
{
    protected $table = 'cms_layout_versions';

    /** @var list<string> */
    protected $fillable = ['placement', 'version', 'blocks', 'block_count', 'content_hash', 'restored_from_version', 'note', 'published_by', 'published_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'placement' => BlockPlacement::class,
            'version' => 'integer',
            'blocks' => 'array',
            'block_count' => 'integer',
            'restored_from_version' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A published CMS version is immutable.'));
        self::deleting(static fn (): never => throw new LogicException('A published CMS version is immutable.'));
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function ofPlacement(Builder $query, BlockPlacement $placement): void
    {
        $query->where('placement', $placement->value);
    }

    public static function latestFor(BlockPlacement $placement): ?self
    {
        return self::query()->ofPlacement($placement)->orderByDesc('version')->first();
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function hash(array $blocks): string
    {
        return hash('sha256', json_encode($blocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
