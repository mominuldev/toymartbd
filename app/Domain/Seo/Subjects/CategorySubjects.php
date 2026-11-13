<?php

declare(strict_types=1);

namespace App\Domain\Seo\Subjects;

use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use App\Domain\Seo\Enums\SeoableType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/** Categorys as SEO subjects. Live = status active. */
final class CategorySubjects implements SeoSubjectProvider
{
    public const string PREFIX = '/categories/';

    public function type(): string
    {
        return SeoableType::Category->value;
    }

    public function find(int $id): ?SeoSubject
    {
        $model = Category::query()->find($id);

        return $model === null ? null : $this->subject($model);
    }

    public function findBySlug(string $slug): ?SeoSubject
    {
        $model = Category::query()->where('slug', $slug)->first();

        return $model === null ? null : $this->subject($model);
    }

    public function live(): iterable
    {
        foreach (Category::query()->where('status', CategoryStatus::Active->value)->lazyById(500) as $model) {
            yield $this->subject($model);
        }
    }

    public function livePaths(array $paths): array
    {
        $slugs = SubjectPaths::slugsUnder(self::PREFIX, $paths);
        if ($slugs === []) {
            return [];
        }

        /** @var list<string> */
        return Category::query()->where('status', CategoryStatus::Active->value)->whereIn('slug', $slugs)->pluck('slug')
            ->map(static fn (string $slug): string => self::PREFIX.$slug)->values()->all();
    }

    private function subject(Category $model): SeoSubject
    {
        $image = $model->banner_path ?? $model->image_path;

        return new SeoSubject(
            type: SeoableType::Category->value,
            id: $model->id,
            slug: $model->slug,
            path: self::PREFIX.$model->slug,
            name: $model->name,
            nameBn: $model->name_bn,
            description: self::plain($model->description),
            descriptionBn: self::plain($model->description_bn),
            image: $image !== null ? Storage::disk('public')->url($image) : null,
            isLive: $model->status === CategoryStatus::Active,
            updatedAt: $model->updated_at === null ? null : CarbonImmutable::instance($model->updated_at),
        );
    }

    private static function plain(?string $text): ?string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));

        return $plain === '' ? null : $plain;
    }
}
