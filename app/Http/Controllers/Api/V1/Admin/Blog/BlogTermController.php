<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Blog;

use App\Domain\Content\Blog\Actions\DeleteBlogTerm;
use App\Domain\Content\Blog\Actions\SaveBlogTerm;
use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Blog\BlogTermRequest;
use App\Http\Resources\Admin\Blog\BlogTermResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;

/**
 * Blog categories, tags and authors (Phase 7): one controller, the kind taken from the route
 * name (`blog.categories.*`, `blog.tags.*`, `blog.authors.*`). Lists are unpaginated (capped
 * at 500) — the editor's pickers need them all. content.view to read, content.manage to write.
 */
final class BlogTermController extends ApiController
{
    public const int MAX = 500;

    /** `GET /blog/{categories|tags|authors}` — with `posts_count` (drafts included). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $model = self::model($request->route());
        $this->authorize('viewAny', $model);

        $query = $model::query()->withCount('posts');
        $query = $model === BlogCategory::class ? $query->orderBy('position')->orderBy('name') : $query->orderBy('name');

        return BlogTermResource::collection($query->orderBy('id')->limit(self::MAX)->get());
    }

    /** `POST /blog/{categories|tags|authors}` */
    public function store(BlogTermRequest $request, SaveBlogTerm $save): JsonResponse
    {
        $model = $request->modelClass();
        $term = $save->handle(new $model, $request->toData(), $request->user()?->id);

        return (new BlogTermResource($term->loadCount('posts')))->response()->setStatusCode(201);
    }

    /** `GET /blog/{categories|tags|authors}/{id}` */
    public function show(Request $request): BlogTermResource
    {
        $term = self::term($request);
        $this->authorize('view', $term);

        return new BlogTermResource($term->loadCount('posts'));
    }

    /** `PATCH /blog/{categories|tags|authors}/{id}` */
    public function update(BlogTermRequest $request, SaveBlogTerm $save): BlogTermResource
    {
        $term = $save->handle(self::term($request), $request->toData(), $request->user()?->id);

        return new BlogTermResource($term->loadCount('posts'));
    }

    /** `DELETE /blog/{categories|tags|authors}/{id}` — a category or author in use is a 422. */
    public function destroy(Request $request, DeleteBlogTerm $delete): Response
    {
        $term = self::term($request);
        $this->authorize('delete', $term);
        $delete->handle($term);

        return response()->noContent();
    }

    private static function term(Request $request): BlogCategory|BlogTag|BlogAuthor
    {
        foreach (['blog_category', 'blog_tag', 'blog_author'] as $param) {
            $value = $request->route($param);
            if ($value instanceof BlogCategory || $value instanceof BlogTag || $value instanceof BlogAuthor) {
                return $value;
            }
        }

        abort(404);
    }

    /** @return class-string<BlogCategory|BlogTag|BlogAuthor> */
    private static function model(mixed $route): string
    {
        $name = $route instanceof Route ? (string) $route->getName() : '';

        return match (true) {
            str_contains($name, 'blog.tags.') => BlogTag::class,
            str_contains($name, 'blog.authors.') => BlogAuthor::class,
            default => BlogCategory::class,
        };
    }
}
