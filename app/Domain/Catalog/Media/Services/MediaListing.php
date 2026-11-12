<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Services;

use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Enums\MediaSort;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Models\MediaFolder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of the media library: the filtered list, the rail's counts and the storage
 * meter (docs/api/media-library.md). Usage questions go through MediaUsages.
 */
final readonly class MediaListing
{
    public function __construct(private MediaUsages $usages) {}

    /**
     * The filtered query, without order (count it, then call `ordered()`).
     *
     * @param  int|'none'|null  $folder
     * @return Builder<Media>
     */
    public function query(int|string|null $folder, bool $unused, ?MediaKind $kind, ?string $missingAlt, ?string $search): Builder
    {
        $query = Media::query();

        if ($folder === 'none') {
            $query->whereNull('folder_id');
        } elseif (is_int($folder)) {
            $query->where('folder_id', $folder);
        }

        if ($unused) {
            $this->usages->whereUsed($query, false);
        }

        if ($kind !== null) {
            $query->where('kind', $kind->value);
        }

        if ($missingAlt !== null) {
            $column = $missingAlt === 'bn' ? 'alt_text_bn' : 'alt_text';
            $query->where('kind', MediaKind::Image->value)
                ->where(static fn (Builder $q) => $q->whereNull($column)->orWhereRaw("TRIM({$column}) = ''"));
        }

        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('filename', 'like', $like)
                    ->orWhere('alt_text', 'like', $like)
                    ->orWhere('alt_text_bn', 'like', $like);
                $this->usages->orWhereSubjectMatches($q, $like);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function ordered(Builder $query, MediaSort $sort): Builder
    {
        return match ($sort) {
            MediaSort::Newest => $query->orderByDesc('created_at')->orderByDesc('id'),
            MediaSort::Oldest => $query->orderBy('created_at')->orderBy('id'),
            MediaSort::Name => $query->orderBy('filename')->orderBy('id'),
            MediaSort::Size => $query->orderByDesc('original_size_bytes')->orderByDesc('id'),
        };
    }

    /**
     * @return array{all: int, unused: int, unfiled: int, folders: list<array{id: int, count: int}>, kinds: array{image: int, document: int}, missing_alt: array{bn: int, en: int}}
     */
    public function counts(): array
    {
        $byFolder = Media::query()->toBase()->selectRaw('folder_id, COUNT(*) as aggregate')->groupBy('folder_id')->pluck('aggregate', 'folder_id');
        $byKind = Media::query()->toBase()->selectRaw('kind, COUNT(*) as aggregate')->groupBy('kind')->pluck('aggregate', 'kind');

        $folders = [];
        foreach (MediaFolder::query()->ordered()->pluck('id') as $id) {
            $folders[] = ['id' => (int) $id, 'count' => (int) ($byFolder[$id] ?? 0)];
        }

        $missing = static fn (string $column): int => Media::query()->where('kind', MediaKind::Image->value)
            ->where(static fn (Builder $q) => $q->whereNull($column)->orWhereRaw("TRIM({$column}) = ''"))->count();

        return [
            'all' => (int) $byKind->sum(),
            'unused' => $this->usages->whereUsed(Media::query(), false)->count(),
            'unfiled' => (int) ($byFolder[''] ?? Media::query()->whereNull('folder_id')->count()),
            'folders' => $folders,
            'kinds' => [
                'image' => (int) ($byKind[MediaKind::Image->value] ?? 0),
                'document' => (int) ($byKind[MediaKind::Document->value] ?? 0),
            ],
            'missing_alt' => ['bn' => $missing('alt_text_bn'), 'en' => $missing('alt_text')],
        ];
    }

    /** @return array{used_bytes: int, originals_bytes: int, renditions_bytes: int, quota_bytes: int|null, file_count: int} */
    public function storage(): array
    {
        $row = Media::query()->toBase()
            ->selectRaw('COUNT(*) as files, COALESCE(SUM(master_bytes), 0) as originals, COALESCE(SUM(renditions_bytes), 0) as renditions')
            ->first();

        $originals = (int) ($row->originals ?? 0);
        $renditions = (int) ($row->renditions ?? 0);
        $quota = config('media.storage_quota_gb');

        return [
            'used_bytes' => $originals + $renditions,
            'originals_bytes' => $originals,
            'renditions_bytes' => $renditions,
            'quota_bytes' => is_numeric($quota) && $quota > 0 ? (int) round((float) $quota * 1073741824) : null,
            'file_count' => (int) ($row->files ?? 0),
        ];
    }
}
