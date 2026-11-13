<?php

declare(strict_types=1);

namespace App\Domain\Seo\Subjects;

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use App\Domain\Seo\Enums\SeoableType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/** Brands as SEO subjects. Live = status active. */
final class BrandSubjects implements SeoSubjectProvider
{
    public const string PREFIX = '/brands/';

    public function type(): string
    {
        return SeoableType::Brand->value;
    }

    public function find(int $id): ?SeoSubject
    {
        $model = Brand::query()->find($id);

        return $model === null ? null : $this->subject($model);
    }

    public function findBySlug(string $slug): ?SeoSubject
    {
        $model = Brand::query()->where('slug', $slug)->first();

        return $model === null ? null : $this->subject($model);
    }

    public function live(): iterable
    {
        foreach (Brand::query()->where('status', BrandStatus::Active->value)->lazyById(500) as $model) {
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
        return Brand::query()->where('status', BrandStatus::Active->value)->whereIn('slug', $slugs)->pluck('slug')
            ->map(static fn (string $slug): string => self::PREFIX.$slug)->values()->all();
    }

    private function subject(Brand $model): SeoSubject
    {
        $image = $model->logo_path;

        return new SeoSubject(
            type: SeoableType::Brand->value,
            id: $model->id,
            slug: $model->slug,
            path: self::PREFIX.$model->slug,
            name: $model->name,
            nameBn: $model->name_bn,
            description: self::plain($model->description),
            descriptionBn: self::plain($model->description_bn),
            image: $image !== null ? Storage::disk('public')->url($image) : null,
            isLive: $model->status === BrandStatus::Active,
            updatedAt: $model->updated_at === null ? null : CarbonImmutable::instance($model->updated_at),
        );
    }

    private static function plain(?string $text): ?string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));

        return $plain === '' ? null : $plain;
    }
}
