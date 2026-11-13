<?php

declare(strict_types=1);

namespace App\Domain\Seo\Subjects;

use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use App\Domain\Seo\Enums\SeoableType;
use App\Domain\Seo\Services\ProductSeo;
use Carbon\CarbonImmutable;

/** Products as SEO subjects. Live = active, published and in the online-store channel. */
final readonly class ProductSubjects implements SeoSubjectProvider
{
    public const string PREFIX = '/products/';

    public function __construct(private ProductSeo $seo) {}

    public function type(): string
    {
        return SeoableType::Product->value;
    }

    public function find(int $id): ?SeoSubject
    {
        $product = Product::query()->with(['images', 'variants'])->find($id);

        return $product === null ? null : $this->subject($product);
    }

    public function findBySlug(string $slug): ?SeoSubject
    {
        $product = Product::query()->with(['images', 'variants'])->where('slug', $slug)->first();

        return $product === null ? null : $this->subject($product);
    }

    public function live(): iterable
    {
        foreach (Product::query()->published()->where('channel_online_store', true)->with(['images', 'variants'])->orderBy('id')->lazyById(500) as $product) {
            yield $this->subject($product);
        }
    }

    public function livePaths(array $paths): array
    {
        $slugs = SubjectPaths::slugsUnder(self::PREFIX, $paths);
        if ($slugs === []) {
            return [];
        }

        /** @var list<string> */
        return Product::query()->published()->where('channel_online_store', true)->whereIn('slug', $slugs)->pluck('slug')
            ->map(static fn (string $slug): string => self::PREFIX.$slug)->values()->all();
    }

    private function subject(Product $product): SeoSubject
    {
        $defaults = $this->seo->defaults($product);

        return new SeoSubject(
            type: SeoableType::Product->value,
            id: $product->id,
            slug: $product->slug,
            path: self::PREFIX.$product->slug,
            name: $product->name,
            nameBn: $product->name_bn,
            description: $defaults['description'],
            descriptionBn: $defaults['description_bn'],
            image: $defaults['og_image'],
            isLive: $product->isLive() && $product->channel_online_store,
            updatedAt: $product->updated_at === null ? null : CarbonImmutable::instance($product->updated_at),
        );
    }
}
