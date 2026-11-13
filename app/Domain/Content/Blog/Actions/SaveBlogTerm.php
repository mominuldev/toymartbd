<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Actions;

use App\Domain\Content\Blog\DTOs\BlogTermData;
use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Content\Blog\Services\BlogCacheTags;
use App\Domain\Content\Blog\Services\BlogSlugHistory;
use App\Domain\Content\Blog\Services\BlogSlugs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a blog category, tag or author. A new term gets an ASCII slug from its
 * name; an existing one changes slug only when one is given (a rename never moves a URL).
 * A slug change of a term with live posts writes slug_history (its archive page was public).
 * Every post that shows the term is purged with the lists.
 */
final class SaveBlogTerm
{
    public function __construct(
        private readonly BlogSlugs $slugs,
        private readonly BlogSlugHistory $history,
    ) {}

    /**
     * @template T of BlogCategory|BlogTag|BlogAuthor
     *
     * @param  T  $term  a new (unsaved) or existing model
     * @return T
     */
    public function handle(BlogCategory|BlogTag|BlogAuthor $term, BlogTermData $data, ?int $actorId = null): BlogCategory|BlogTag|BlogAuthor
    {
        try {
            return DB::transaction(function () use ($term, $data, $actorId): BlogCategory|BlogTag|BlogAuthor {
                $isNew = ! $term->exists;
                $previousSlug = $isNew ? null : $term->slug;
                $wasPublic = ! $isNew && $this->livePosts($term)->exists();

                $term->name = $data->name;
                $term->name_bn = $data->nameBn;

                if ($term instanceof BlogCategory) {
                    $term->description = $data->description;
                    $term->description_bn = $data->descriptionBn;
                    if ($data->position !== null) {
                        $term->position = $data->position;
                    }
                } elseif ($term instanceof BlogAuthor) {
                    $term->bio = $data->description;
                    $term->bio_bn = $data->descriptionBn;
                    $term->avatar_media_id = $data->avatarMediaId;
                    $term->user_id = $data->userId;
                }

                if ($data->slug !== null && $data->slug !== '') {
                    $term->slug = $data->slug;
                } elseif ($isNew) {
                    $term->slug = $this->slugs->generate($term::class, $data->name, $data->nameBn);
                }

                $term->save();

                $this->history->record($term::SEO_TYPE, $term->id, $previousSlug, $term->slug, $wasPublic, $actorId);

                if (! $isNew) {
                    BlogCacheTags::purge($this->livePosts($term)->pluck('blog_posts.slug', 'blog_posts.id')->all());
                }

                return $term;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }

    /** @return Builder<BlogPost> */
    private function livePosts(BlogCategory|BlogTag|BlogAuthor $term): Builder
    {
        $query = BlogPost::query()->live();

        return match (true) {
            $term instanceof BlogCategory => $query->where('category_id', $term->id),
            $term instanceof BlogAuthor => $query->where('author_id', $term->id),
            default => $query->whereExists(static fn ($q) => $q->selectRaw('1')->from('blog_post_tag')
                ->whereColumn('blog_post_tag.post_id', 'blog_posts.id')->where('blog_post_tag.tag_id', $term->id)),
        };
    }
}
