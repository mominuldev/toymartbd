<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Blog;

use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\Blog\StorefrontBlogPostCardResource;
use App\Http\Resources\Storefront\Blog\StorefrontBlogPostResource;
use App\Http\Resources\Storefront\Blog\StorefrontBlogTermResource;
use App\Support\PreviewToken;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Storefront blog reads (Phase 7). Live posts only (not draft, `published_at` reached).
 * The Next.js server caches them by tag — `content:blog` for every list and taxonomy page,
 * `content:post:{id}` for one post — and Laravel purges both on every write and at the
 * scheduled-publish instant (docs/api/revalidation.md).
 */
final class BlogController extends ApiController
{
    public const int PER_PAGE = 12;

    public const int MAX_PER_PAGE = 24;

    public const int MAX_TEASER = 6;

    /**
     * `GET /blog/posts` — page-paginated (numbered pages, for `rel=prev/next` and canonical
     * page URLs), newest first. Filters by slug: `filter[category]`, `filter[tag]`,
     * `filter[author]`; `filter[type]` = `article|buying_guide`. Anything else is a 400.
     */
    #[QueryParameter('filter[category]', 'Blog category slug.', type: 'string')]
    #[QueryParameter('filter[tag]', 'Blog tag slug.', type: 'string')]
    #[QueryParameter('filter[author]', 'Blog author slug.', type: 'string')]
    #[QueryParameter('filter[type]', 'Article or buying guide.', type: "'article'|'buying_guide'")]
    #[QueryParameter('page', 'Page number (1-based).', type: 'int', default: 1)]
    #[QueryParameter('per_page', 'Posts per page, 1–24.', type: 'int', default: 12)]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->query('per_page', (string) self::PER_PAGE);
        if (! is_string($perPage) || preg_match('/^\d{1,2}$/', $perPage) !== 1 || (int) $perPage < 1 || (int) $perPage > self::MAX_PER_PAGE) {
            throw new BadRequestHttpException('per_page must be between 1 and '.self::MAX_PER_PAGE.'.');
        }
        $page = $request->query('page', '1');
        if (! is_string($page) || preg_match('/^\d{1,5}$/', $page) !== 1 || (int) $page < 1) {
            throw new BadRequestHttpException('page must be a positive integer.');
        }

        $slugFilter = static fn (string $name, callable $apply): AllowedFilter => AllowedFilter::callback($name, static function (Builder $query, mixed $value) use ($name, $apply): void {
            if (! is_string($value) || preg_match('/^[a-z0-9-]{1,94}$/', $value) !== 1) {
                throw new BadRequestHttpException("filter[{$name}] must be a slug.");
            }
            $apply($query, $value);
        });

        $posts = QueryBuilder::for(BlogPost::query()->live(), $request)
            ->allowedFilters(
                $slugFilter('category', static fn (Builder $q, string $slug) => $q->whereIn('category_id', BlogCategory::query()->where('slug', $slug)->select('id'))),
                $slugFilter('author', static fn (Builder $q, string $slug) => $q->whereIn('author_id', BlogAuthor::query()->where('slug', $slug)->select('id'))),
                $slugFilter('tag', static fn (Builder $q, string $slug) => $q->whereExists(static fn ($sub) => $sub->selectRaw('1')->from('blog_post_tag')
                    ->join('blog_tags', 'blog_tags.id', '=', 'blog_post_tag.tag_id')
                    ->whereColumn('blog_post_tag.post_id', 'blog_posts.id')->where('blog_tags.slug', $slug))),
                AllowedFilter::callback('type', static function (Builder $query, mixed $value): void {
                    $type = is_string($value) ? PostType::tryFrom($value) : null;
                    if ($type === null) {
                        throw new BadRequestHttpException('filter[type] must be one of: article, buying_guide.');
                    }
                    $query->where('type', $type->value);
                }),
            )
            ->with(['category', 'author', 'cover'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) $perPage, ['*'], 'page', (int) $page)
            ->withQueryString();

        return StorefrontBlogPostCardResource::collection($posts);
    }

    /** `GET /blog/teaser` — the home-page blog block: featured posts first, then the newest (`limit` 1–6, default 3). */
    #[QueryParameter('limit', 'How many cards, 1–6.', type: 'int', default: 3)]
    public function teaser(Request $request): AnonymousResourceCollection
    {
        $limit = max(1, min(self::MAX_TEASER, (int) $request->query('limit', '3')));

        $posts = BlogPost::query()->live()->with(['category', 'author', 'cover'])
            ->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id')
            ->limit($limit)->get();

        return StorefrontBlogPostCardResource::collection($posts);
    }

    /** `GET /blog/posts/{slug}` — one live post or guide; 404 otherwise (an old slug is the redirect middleware's job). */
    #[OpenApiResponse(404, 'No live post has this slug')]
    public function show(string $slug): StorefrontBlogPostResource
    {
        $post = BlogPost::query()->live()->where('slug', $slug)->firstOrFail();

        return new StorefrontBlogPostResource($post);
    }

    /** `GET /blog/preview/{token}` — any post, by a signed preview token from the admin. Never cache this. */
    #[OpenApiResponse(404, 'Invalid, forged or expired token')]
    public function preview(string $token): StorefrontBlogPostResource
    {
        $id = PreviewToken::verify($token, BlogPost::SEO_TYPE);
        $post = $id === null ? null : BlogPost::query()->find($id);
        if ($post === null) {
            throw new NotFoundHttpException(__('blog.preview_invalid'));
        }

        return new StorefrontBlogPostResource($post);
    }

    /** `GET /blog/categories` — categories with at least one live post, in admin order. */
    public function categories(): AnonymousResourceCollection
    {
        $categories = BlogCategory::query()
            ->withCount(['posts' => self::liveOnly(...)])
            ->orderBy('position')->orderBy('name')->orderBy('id')
            ->get()
            ->filter(static fn (BlogCategory $c): bool => (int) $c->getAttribute('posts_count') > 0)
            ->values();

        return StorefrontBlogTermResource::collection($categories);
    }

    /** `GET /blog/categories/{slug}` */
    #[OpenApiResponse(404, 'No blog category has this slug')]
    public function category(string $slug): StorefrontBlogTermResource
    {
        return $this->term(BlogCategory::class, $slug);
    }

    /** `GET /blog/tags/{slug}` */
    #[OpenApiResponse(404, 'No blog tag has this slug')]
    public function tag(string $slug): StorefrontBlogTermResource
    {
        return $this->term(BlogTag::class, $slug);
    }

    /** `GET /blog/authors/{slug}` */
    #[OpenApiResponse(404, 'No blog author has this slug')]
    public function author(string $slug): StorefrontBlogTermResource
    {
        return $this->term(BlogAuthor::class, $slug);
    }

    /** @param  Builder<BlogPost>  $query */
    private static function liveOnly(Builder $query): void
    {
        $query->live();
    }

    /** @param  class-string<BlogCategory|BlogTag|BlogAuthor>  $model */
    private function term(string $model, string $slug): StorefrontBlogTermResource
    {
        $term = $model::query()
            ->withCount(['posts' => self::liveOnly(...)])
            ->where('slug', $slug)
            ->firstOrFail();

        return new StorefrontBlogTermResource($term);
    }
}
