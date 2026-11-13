<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Enums\MenuLinkType;
use App\Domain\Content\Enums\MenuLocation;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;

/**
 * A menu's flat rows → the nested tree, with every link resolved server-side.
 *
 * - admin(): every item, hidden ones included, each with its resolved `target` (null for custom
 *   URLs and headings) so the editor can flag a link to an archived page.
 * - storefront(): only what a shopper may see — hidden items and items whose target is not
 *   live are dropped together with their children; `href` is the locale-free path.
 *
 * @phpstan-type AdminItem array{id: int, label: string, label_bn: string|null, link_type: string, link_id: int|null, url: string|null, is_visible: bool, open_in_new_tab: bool, target: array{label: string, label_bn: string|null, path: string, live: bool}|null, children: list<mixed>}
 * @phpstan-type StoreItem array{label: string, label_bn: string|null, type: string, href: string|null, open_in_new_tab: bool, children: list<mixed>}
 */
final class MenuTree
{
    public function __construct(private readonly MenuLinks $links) {}

    /** @return list<AdminItem> */
    public function admin(Menu $menu): array
    {
        [$byParent, $targets] = $this->load($menu);

        return $this->adminLevel($byParent, $targets, 0);
    }

    /** @return list<StoreItem> */
    public function storefront(Menu $menu): array
    {
        [$byParent, $targets] = $this->load($menu);

        return $this->storeLevel($byParent, $targets, 0, 1, $menu->location);
    }

    /**
     * @return array{0: array<int, list<MenuItem>>, 1: array<string, array<int, array{id: int, label: string, label_bn: string|null, path: string, live: bool}>>}
     */
    private function load(Menu $menu): array
    {
        $items = $menu->relationLoaded('items') ? $menu->items : $menu->items()->get();

        $byParent = [];
        foreach ($items as $item) {
            $byParent[$item->parent_id ?? 0][] = $item;
        }

        $targets = $this->links->resolve($items->map(static fn (MenuItem $i): array => [$i->link_type, $i->link_id])->all());

        return [$byParent, $targets];
    }

    /**
     * @param  array<int, list<MenuItem>>  $byParent
     * @param  array<string, array<int, array{id: int, label: string, label_bn: string|null, path: string, live: bool}>>  $targets
     * @return list<AdminItem>
     */
    private function adminLevel(array $byParent, array $targets, int $parent): array
    {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $item) {
            $target = null;
            if ($item->link_type->needsTarget()) {
                $t = $targets[$item->link_type->value][(int) $item->link_id] ?? null;
                $target = $t === null
                    ? ['label' => '', 'label_bn' => null, 'path' => '', 'live' => false]
                    : ['label' => $t['label'], 'label_bn' => $t['label_bn'], 'path' => $t['path'], 'live' => $t['live']];
            }

            $out[] = [
                'id' => $item->id,
                'label' => $item->label,
                'label_bn' => $item->label_bn,
                'link_type' => $item->link_type->value,
                'link_id' => $item->link_id,
                'url' => $item->url,
                'is_visible' => $item->is_visible,
                'open_in_new_tab' => $item->open_in_new_tab,
                'target' => $target,
                'children' => $this->adminLevel($byParent, $targets, $item->id),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, list<MenuItem>>  $byParent
     * @param  array<string, array<int, array{id: int, label: string, label_bn: string|null, path: string, live: bool}>>  $targets
     * @return list<StoreItem>
     */
    private function storeLevel(array $byParent, array $targets, int $parent, int $depth, MenuLocation $location): array
    {
        if ($depth > $location->maxDepth()) {
            return [];
        }

        $out = [];
        foreach ($byParent[$parent] ?? [] as $item) {
            if (! $item->is_visible) {
                continue;
            }

            $href = match ($item->link_type) {
                MenuLinkType::Custom => $item->url,
                MenuLinkType::CategoryTree => '/categories',
                MenuLinkType::None => null,
                default => ($targets[$item->link_type->value][(int) $item->link_id] ?? null)['path'] ?? null,
            };

            if ($item->link_type->needsTarget()) {
                $live = $targets[$item->link_type->value][(int) $item->link_id]['live'] ?? false;
                if (! $live) {
                    continue;
                }
            }

            $children = $this->storeLevel($byParent, $targets, $item->id, $depth + 1, $location);

            // A heading whose every link was hidden says nothing on its own.
            if ($item->link_type === MenuLinkType::None && $children === []) {
                continue;
            }

            $out[] = [
                'label' => $item->label,
                'label_bn' => $item->label_bn,
                'type' => $item->link_type->value,
                'href' => $href,
                'open_in_new_tab' => $item->open_in_new_tab,
                'children' => $children,
            ];
        }

        return $out;
    }
}
