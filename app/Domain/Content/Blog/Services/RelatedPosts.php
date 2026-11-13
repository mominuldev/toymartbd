<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Services;

use App\Domain\Content\Blog\Models\BlogPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Related posts" under a post: live posts only, never the post itself. Ranked by
 * shared tags (1 point each) plus 2 points for the same category, then by recency;
 * when fewer than `$limit` share anything, the most recent live posts fill the rest.
 * Computed on read, server-side (rule 1) — never on checkout (rule 25b).
 */
final class RelatedPosts
{
    public const int DEFAULT_LIMIT = 3;

    /** @return Collection<int, BlogPost> */
    public function for(BlogPost $post, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $tagIds = DB::table('blog_post_tag')->where('post_id', $post->id)->pluck('tag_id')->map(intval(...))->all();

        $score = DB::table('blog_post_tag')
            ->selectRaw('COUNT(*)')
            ->whereColumn('blog_post_tag.post_id', 'blog_posts.id')
            ->whereIn('blog_post_tag.tag_id', $tagIds === [] ? [0] : $tagIds);

        $categoryPoints = $post->category_id === null
            ? '0'
            : 'CASE WHEN blog_posts.category_id = '.(int) $post->category_id.' THEN 2 ELSE 0 END';

        /** @var Collection<int, BlogPost> $ranked */
        $ranked = $this->base($post)
            ->select('blog_posts.*')
            ->selectSub($score, 'shared_tags')
            ->selectRaw($categoryPoints.' as category_points')
            ->get()
            ->filter(static fn (BlogPost $p): bool => ((int) $p->getAttribute('shared_tags') + (int) $p->getAttribute('category_points')) > 0)
            ->sortBy([
                static fn (BlogPost $a, BlogPost $b): int => self::score($b) <=> self::score($a),
                static fn (BlogPost $a, BlogPost $b): int => ($b->published_at?->getTimestamp() ?? 0) <=> ($a->published_at?->getTimestamp() ?? 0),
                static fn (BlogPost $a, BlogPost $b): int => $b->id <=> $a->id,
            ])
            ->take($limit)
            ->values();

        if ($ranked->count() < $limit) {
            $fill = $this->base($post)
                ->whereNotIn('blog_posts.id', $ranked->pluck('id')->all() ?: [0])
                ->orderByDesc('blog_posts.published_at')
                ->orderByDesc('blog_posts.id')
                ->limit($limit - $ranked->count())
                ->get();
            $ranked = $ranked->concat($fill)->values();
        }

        $ranked->load(['category', 'cover', 'author']);

        return new Collection($ranked->all());
    }

    /** @return Builder<BlogPost> */
    private function base(BlogPost $post): Builder
    {
        return BlogPost::query()->live()->where('blog_posts.id', '!=', $post->id);
    }

    private static function score(BlogPost $post): int
    {
        return (int) $post->getAttribute('shared_tags') + (int) $post->getAttribute('category_points');
    }
}
