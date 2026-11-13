<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Seo\DTOs\RedirectData;
use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Services\LivePaths;
use App\Domain\Seo\Services\PathNormalizer;
use App\Domain\Seo\Services\RedirectMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a manual redirect (docs/api/seo.md §2). Enforces, in one transaction:
 *
 * - paths are locale-independent and well-formed; the source is not a live page or a
 *   built-in route, and not already redirected by another row;
 * - no loop: the target, followed through every existing redirect (manual and
 *   slug-history), never comes back to the source;
 * - no chain: the stored target is the final destination, and rows that pointed at
 *   the new source are re-pointed past it.
 */
final readonly class SaveRedirect
{
    public function __construct(private LivePaths $live, private RedirectMap $map) {}

    public function handle(?Redirect $existing, RedirectData $data, ?int $actorId = null, string $source = Redirect::SOURCE_MANUAL): Redirect
    {
        $from = PathNormalizer::path($data->fromPath) ?? self::fail('from_path', __('seo.redirect.from_invalid'));
        $to = PathNormalizer::target($data->toPath) ?? self::fail('to_path', __('seo.redirect.to_invalid'));

        if (PathNormalizer::targetPath($to) === $from) {
            self::fail('to_path', __('seo.redirect.same'));
        }
        if ($this->live->reserved($from)) {
            self::fail('from_path', __('seo.redirect.reserved', ['path' => $from]));
        }
        if ($this->live->isLive($from)) {
            self::fail('from_path', __('seo.redirect.live', ['path' => $from]));
        }

        $redirect = DB::transaction(function () use ($existing, $data, $from, $to, $actorId, $source): Redirect {
            $duplicate = Redirect::query()->where('from_path', $from)
                ->when($existing !== null, static fn ($q) => $q->whereKeyNot($existing?->id))
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                self::fail('from_path', __('seo.redirect.duplicate', ['path' => $from]));
            }

            $final = $to;
            if ($data->isActive) {
                $map = [];
                foreach ($this->map->build() as $entry) {
                    if ($entry['from'] !== $from && ($existing === null || $entry['from'] !== $existing->from_path)) {
                        $map[$entry['from']] = $entry;
                    }
                }
                $final = RedirectMap::follow($map, $to, $from);
                if ($final === null || PathNormalizer::targetPath($final) === $from) {
                    self::fail('to_path', __('seo.redirect.loop', ['chain' => $from.' → '.$to.' → … → '.$from]));
                }
            }

            $redirect = $existing ?? new Redirect(['source' => $source, 'created_by' => $actorId]);
            $redirect->fill([
                'from_path' => $from,
                'to_path' => $final,
                'status_code' => $data->statusCode,
                'is_active' => $data->isActive,
                'note' => $data->note,
            ]);
            $redirect->save();

            if ($data->isActive) {
                Redirect::query()
                    ->whereKeyNot($redirect->id)
                    ->where(static fn ($q) => $q->where('to_path', $from)->orWhere('to_path', 'like', addcslashes($from, '\\%_').'?%'))
                    ->update(['to_path' => $final, 'updated_at' => now()]);
            }

            return $redirect;
        });

        DB::afterCommit(static fn () => RedirectMap::forget());
        RedirectMap::forget();
        SeoPagesStale::dispatch(['redirects']);

        return $redirect;
    }

    private static function fail(string $field, mixed $message): never
    {
        throw ValidationException::withMessages([$field => is_string($message) ? $message : '']);
    }
}
