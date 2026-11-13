<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Actions;

use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Services\BlogCacheTags;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled-publish sweep (every minute): every `scheduled` post whose `published_at`
 * (UTC) has been reached becomes `published`, and the storefront drops its blog lists and
 * those posts' pages. The API already serves a due post at its instant (the `live` scope);
 * this sweep is what makes the ISR cache show it. Returns the ids it published.
 */
final class PublishDueBlogPosts
{
    /** @return list<int> */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            /** @var array<int, string> $due id => slug */
            $due = BlogPost::query()
                ->where('status', PostStatus::Scheduled->value)
                ->where('published_at', '<=', CarbonImmutable::now())
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('slug', 'id')
                ->all();
            $ids = array_map(intval(...), array_keys($due));

            if ($ids === []) {
                return [];
            }

            BlogPost::query()->whereIn('id', $ids)->update(['status' => PostStatus::Published->value]);
            BlogCacheTags::purge($due);

            return $ids;
        });
    }
}
