<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Enums\MediaSort;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The `GET /media` query against an explicit allowlist (CLAUDE.md rule 16): an unlisted
 * filter, an unknown sort or a malformed value is a 400 `bad-request`, never ignored.
 */
final readonly class MediaListQuery
{
    public const array FILTERS = ['folder', 'unused', 'kind', 'missing_alt', 'search'];

    /**
     * @param  int|'none'|null  $folder
     */
    private function __construct(
        public int|string|null $folder,
        public bool $unused,
        public ?MediaKind $kind,
        public ?string $missingAlt,
        public ?string $search,
        public MediaSort $sort,
        public int $perPage,
    ) {}

    /** @throws BadRequestHttpException */
    public static function from(Request $request): self
    {
        $filter = $request->query('filter', []);

        if (! is_array($filter)) {
            throw new BadRequestHttpException('`filter` must be an object: filter[name]=value.');
        }

        $unknown = array_diff(array_map(strval(...), array_keys($filter)), self::FILTERS);

        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown filter(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::FILTERS).'.');
        }

        foreach ($filter as $key => $value) {
            if (! is_string($value)) {
                throw new BadRequestHttpException("filter[{$key}] must be a single value.");
            }
        }

        /** @var array<string, string> $filter */
        $folder = null;
        $rawFolder = $filter['folder'] ?? '';

        if ($rawFolder === 'none') {
            $folder = 'none';
        } elseif ($rawFolder !== '') {
            if (! ctype_digit($rawFolder) || (int) $rawFolder === 0) {
                throw new BadRequestHttpException('filter[folder] must be a folder id or `none`.');
            }
            $folder = (int) $rawFolder;
        }

        $unused = $filter['unused'] ?? '';

        if (! in_array($unused, ['', '0', '1'], true)) {
            throw new BadRequestHttpException('filter[unused] must be 1.');
        }

        $kind = null;

        if (($filter['kind'] ?? '') !== '') {
            $kind = MediaKind::tryFrom($filter['kind']) ?? throw new BadRequestHttpException('filter[kind] must be one of: image, document.');
        }

        $missingAlt = ($filter['missing_alt'] ?? '') === '' ? null : $filter['missing_alt'];

        if ($missingAlt !== null && ! in_array($missingAlt, ['bn', 'en'], true)) {
            throw new BadRequestHttpException('filter[missing_alt] must be one of: bn, en.');
        }

        $search = trim($filter['search'] ?? '');
        $search = $search === '' ? null : mb_substr($search, 0, 100);

        $sortValue = $request->query('sort', MediaSort::Newest->value);
        $sort = is_string($sortValue) ? MediaSort::tryFrom($sortValue) : null;

        if ($sort === null) {
            throw new BadRequestHttpException('sort must be one of: '.implode(', ', MediaSort::values()).'.');
        }

        $default = config('media.per_page');
        $perPageValue = $request->query('per_page', (string) (is_int($default) ? $default : 30));

        if (! is_string($perPageValue) || ! ctype_digit($perPageValue) || (int) $perPageValue < 1 || (int) $perPageValue > 100) {
            throw new BadRequestHttpException('per_page must be an integer from 1 to 100.');
        }

        return new self($folder, $unused === '1', $kind, $missingAlt, $search, $sort, (int) $perPageValue);
    }
}
