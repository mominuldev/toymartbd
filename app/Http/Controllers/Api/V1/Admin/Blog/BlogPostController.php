<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Blog;

use App\Domain\Content\Blog\Actions\DeleteBlogPost;
use App\Domain\Content\Blog\Actions\SaveBlogPost;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Blog\BlogPostRequest;
use App\Http\Resources\Admin\Blog\BlogPostListResource;
use App\Http\Resources\Admin\Blog\BlogPostResource;
use App\Domain\Seo\Services\SiteUrl;
use App\Support\PreviewToken;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Blog posts and buying guides (Phase 7). content.view to read, content.manage to write. */
final class BlogPostController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /blog/posts` — page-paginated, newest first by default.
     *
     * Filters: `filter[search]` (title, title_bn or slug), `filter[status]`, `filter[type]`,
     * `filter[category]` (id), `filter[author]` (id). Sorts: `updated_at`, `published_at`,
     * `title` (prefix `-` for descending). Anything else is a 400.
     */
    #[QueryParameter('filter[search]', 'Contains match on title, title_bn or slug.', type: 'string')]
    #[QueryParameter('filter[status]', 'Editorial status.', type: "'draft'|'scheduled'|'published'")]
    #[QueryParameter('filter[type]', 'Article or buying guide.', type: "'article'|'buying_guide'")]
    #[QueryParameter('filter[category]', 'Blog category id.', type: 'int')]
    #[QueryParameter('filter[author]', 'Blog author id.', type: 'int')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'updated_at'|'-updated_at'|'published_at'|'-published_at'|'title'|'-title'", default: '-updated_at')]
    #[QueryParameter('page', 'Page number.', type: 'int', default: 1)]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BlogPost::class);

        $sort = $request->query('sort');
        $descending = ! is_string($sort) || str_starts_with($sort, '-');

        $posts = QueryBuilder::for(BlogPost::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $like = '%'.addcslashes($term, '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('title_bn', 'like', $like)->orWhere('slug', 'like', $like));
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $status = is_string($value) ? PostStatus::tryFrom($value) : null;
                    if ($status === null) {
                        throw new BadRequestHttpException('filter[status] must be one of: draft, scheduled, published.');
                    }
                    $query->where('status', $status->value);
                }),
                AllowedFilter::callback('type', static function (Builder $query, mixed $value): void {
                    $type = is_string($value) ? PostType::tryFrom($value) : null;
                    if ($type === null) {
                        throw new BadRequestHttpException('filter[type] must be one of: article, buying_guide.');
                    }
                    $query->where('type', $type->value);
                }),
                AllowedFilter::callback('category', static fn (Builder $q, mixed $v) => $q->where('category_id', self::id($v, 'category'))),
                AllowedFilter::callback('author', static fn (Builder $q, mixed $v) => $q->where('author_id', self::id($v, 'author'))),
            )
            ->allowedSorts('updated_at', 'published_at', 'title')
            ->defaultSort('-updated_at')
            ->with(['category', 'author', 'cover'])
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return BlogPostListResource::collection($posts);
    }

    /** `POST /blog/posts` — needs content.manage. */
    public function store(BlogPostRequest $request, SaveBlogPost $save): JsonResponse
    {
        $post = $save->handle(null, $request->toData(), $request->user()?->id);

        return (new BlogPostResource($post))->response()->setStatusCode(201);
    }

    /** `GET /blog/posts/{post}` — the editor document. */
    public function show(BlogPost $post): BlogPostResource
    {
        $this->authorize('view', $post);

        return new BlogPostResource($post);
    }

    /** `PUT /blog/posts/{post}` — replaces the document; needs content.manage. */
    public function update(BlogPostRequest $request, BlogPost $post, SaveBlogPost $save): BlogPostResource
    {
        return new BlogPostResource($save->handle($post, $request->toData(), $request->user()?->id));
    }

    /** `DELETE /blog/posts/{post}` — drafts only (422 otherwise); needs content.manage. */
    public function destroy(BlogPost $post, DeleteBlogPost $delete): Response
    {
        $this->authorize('delete', $post);
        $delete->handle($post);

        return response()->noContent();
    }

    /**
     * `POST /blog/posts/{post}/preview-link` — a signed, 24-hour token for the storefront's
     * private preview route (`/blog/preview/{token}`, noindex, uncached). Needs content.manage.
     *
     * @response array{token: string, path: string, path_bn: string, url: string, url_bn: string, expires_in: int}
     */
    public function previewLink(BlogPost $post): JsonResponse
    {
        $this->authorize('update', $post);
        $token = PreviewToken::issue(BlogPost::SEO_TYPE, $post->id);

        return response()->json([
            'token' => $token,
            'path' => '/blog/preview/'.$token,
            'path_bn' => '/bn/blog/preview/'.$token,
            'url' => SiteUrl::localized('/blog/preview/'.$token, 'en'),
            'url_bn' => SiteUrl::localized('/blog/preview/'.$token, 'bn'),
            'expires_in' => PreviewToken::DEFAULT_TTL_SECONDS,
        ]);
    }

    private static function id(mixed $value, string $name): int
    {
        if (! is_string($value) || preg_match('/^\d{1,10}$/', $value) !== 1) {
            throw new BadRequestHttpException("filter[{$name}] must be an id.");
        }

        return (int) $value;
    }
}
