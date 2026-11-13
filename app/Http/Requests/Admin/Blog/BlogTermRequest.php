<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Blog;

use App\Domain\Content\Blog\DTOs\BlogTermData;
use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Create or edit a blog category (`/blog/categories`), tag (`/blog/tags`) or author
 * (`/blog/authors`). `slug` omitted keeps the current one (new: generated from the name).
 * `description(_bn)` is a category description or an author bio; `position` is for
 * categories, `avatar_media_id` and `user_id` for authors.
 */
final class BlogTermRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $term = $this->term();

        return $term !== null
            ? ($this->user()?->can('update', $term) ?? false)
            : ($this->user()?->can('create', $this->modelClass()) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $term = $this->term();
        $model = $this->modelClass();
        $table = (new $model)->getTable();
        $isTag = $model === BlogTag::class;

        return [
            'name' => ['required', 'string', 'max:'.($isTag ? 80 : 120)],
            'name_bn' => ['nullable', 'string', 'max:'.($isTag ? 80 : 120)],
            'slug' => [
                'nullable', 'string', 'max:'.BlogPost::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN,
                Rule::unique($table, 'slug')->ignore($term?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:'.($model === BlogAuthor::class ? 5000 : 1000)],
            'description_bn' => ['nullable', 'string', 'max:'.($model === BlogAuthor::class ? 5000 : 1000)],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'avatar_media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where('kind', 'image')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    public function toData(): BlogTermData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $text = static fn (string $k): ?string => is_string($v[$k] ?? null) && trim($v[$k]) !== '' ? trim($v[$k]) : null;

        return new BlogTermData(
            name: trim((string) $v['name']),
            nameBn: $text('name_bn'),
            slug: $text('slug'),
            description: $text('description'),
            descriptionBn: $text('description_bn'),
            position: isset($v['position']) ? (int) $v['position'] : null,
            avatarMediaId: isset($v['avatar_media_id']) ? (int) $v['avatar_media_id'] : null,
            userId: isset($v['user_id']) ? (int) $v['user_id'] : null,
        );
    }

    /** @return class-string<BlogCategory|BlogTag|BlogAuthor> */
    public function modelClass(): string
    {
        $name = (string) $this->route()?->getName();

        return match (true) {
            str_contains($name, 'blog.tags.') => BlogTag::class,
            str_contains($name, 'blog.authors.') => BlogAuthor::class,
            default => BlogCategory::class,
        };
    }

    private function term(): ?Model
    {
        foreach (['blog_category', 'blog_tag', 'blog_author'] as $param) {
            $value = $this->route($param);
            if ($value instanceof Model) {
                return $value;
            }
        }

        return null;
    }
}
