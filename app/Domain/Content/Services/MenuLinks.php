<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Enums\MenuLinkType;
use App\Domain\Content\Models\CmsPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Resolves menu link targets to storefront paths, server-side (rule 1: the storefront never
 * builds a URL from an id). A target that is archived, unpublished or gone resolves as not
 * live, and the storefront menu hides the item.
 *
 * Paths are locale-free; the storefront adds `/bn` for Bangla.
 *
 * @phpstan-type Target array{id: int, label: string, label_bn: string|null, path: string, live: bool}
 */
final class MenuLinks
{
    public const int SEARCH_LIMIT = 20;

    /**
     * @param  iterable<array{0: MenuLinkType, 1: int|null}>  $links
     * @return array<string, array<int, Target>> link type → id → target
     */
    public function resolve(iterable $links): array
    {
        $ids = [];
        foreach ($links as [$type, $id]) {
            if ($type->needsTarget() && $id !== null) {
                $ids[$type->value][$id] = $id;
            }
        }

        $out = [];
        foreach ($ids as $type => $list) {
            $linkType = MenuLinkType::from($type);
            foreach ($this->query($linkType)->whereKey(array_values($list))->get() as $model) {
                $target = $this->target($linkType, $model);
                $out[$type][$target['id']] = $target;
            }
        }

        return $out;
    }

    /**
     * Admin link picker: targets of one type whose name or slug contains `$term`, live first.
     *
     * @return list<Target>
     */
    public function search(MenuLinkType $type, string $term): array
    {
        if (! $type->needsTarget()) {
            return [];
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        [$name, $nameBn] = $this->nameColumns($type);

        $models = $this->query($type)
            ->where(static function (Builder $q) use ($like, $name, $nameBn): void {
                $q->where($name, 'like', $like)->orWhere($nameBn, 'like', $like)->orWhere('slug', 'like', $like);
            })
            ->orderBy($name)
            ->limit(self::SEARCH_LIMIT)
            ->get();

        $targets = array_map(fn (Model $m): array => $this->target($type, $m), $models->all());
        usort($targets, static fn (array $a, array $b): int => (int) $b['live'] <=> (int) $a['live']);

        return $targets;
    }

    /** @return Builder<covariant Model> */
    private function query(MenuLinkType $type): Builder
    {
        return match ($type) {
            MenuLinkType::Category => Category::query(),
            MenuLinkType::Brand => Brand::query(),
            MenuLinkType::Product => Product::query(),
            MenuLinkType::Page => CmsPage::query(),
            MenuLinkType::Post => BlogPost::query(),
            default => throw new LogicException("Menu link type {$type->value} has no target."),
        };
    }

    /** @return array{0: string, 1: string} */
    private function nameColumns(MenuLinkType $type): array
    {
        return in_array($type, [MenuLinkType::Page, MenuLinkType::Post], true) ? ['title', 'title_bn'] : ['name', 'name_bn'];
    }

    /** @return Target */
    private function target(MenuLinkType $type, Model $model): array
    {
        return match (true) {
            $model instanceof Category => [
                'id' => $model->id, 'label' => $model->name, 'label_bn' => $model->name_bn,
                'path' => '/categories/'.$model->slug, 'live' => $model->status->value === 'active',
            ],
            $model instanceof Brand => [
                'id' => $model->id, 'label' => $model->name, 'label_bn' => $model->name_bn,
                'path' => '/brands/'.$model->slug, 'live' => $model->isActive(),
            ],
            $model instanceof Product => [
                'id' => $model->id, 'label' => $model->name, 'label_bn' => $model->name_bn,
                'path' => '/products/'.$model->slug, 'live' => $model->isLive(),
            ],
            $model instanceof CmsPage => [
                'id' => $model->id, 'label' => $model->title, 'label_bn' => $model->title_bn,
                'path' => '/pages/'.$model->slug, 'live' => $model->isLive(),
            ],
            $model instanceof BlogPost => [
                'id' => $model->id, 'label' => $model->title, 'label_bn' => $model->title_bn,
                'path' => ($model->type === PostType::BuyingGuide ? '/guides/' : '/blog/').$model->slug, 'live' => $model->isLive(),
            ],
            default => throw new LogicException("Unexpected menu target for {$type->value}."),
        };
    }
}
