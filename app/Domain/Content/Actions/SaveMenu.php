<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\DTOs\MenuItemData;
use App\Domain\Content\Enums\MenuLinkType;
use App\Domain\Content\Enums\MenuLocation;
use App\Domain\Content\Events\MenusChanged;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Replace one location's menu: its published flag and its whole item tree, in order. Order
 * in the list is the display order, so a drag or keyboard reorder is a save of the new tree.
 * The FormRequest validates shape and targets; the depth and size caps are re-checked here
 * because they are the menu's invariants, not the request's.
 */
final class SaveMenu
{
    /** @param  list<MenuItemData>  $items */
    public function handle(MenuLocation $location, bool $isPublished, array $items, ?int $actorId = null): Menu
    {
        $count = self::count($items, 1, $location->maxDepth());
        if ($count > $location->maxItems()) {
            throw new InvalidArgumentException("A {$location->value} menu holds at most {$location->maxItems()} items.");
        }

        return DB::transaction(function () use ($location, $isPublished, $items, $actorId): Menu {
            $menu = Menu::query()->lockForUpdate()->firstOrNew(['location' => $location]);

            $menu->fill([
                'is_published' => $isPublished,
                'published_at' => $isPublished ? ($menu->is_published ? $menu->published_at : now()) : null,
                'updated_by' => $actorId,
            ]);
            $menu->save();

            MenuItem::query()->where('menu_id', $menu->id)->whereNull('parent_id')->delete();
            $this->insert($menu->id, null, $items);
            $menu->touch();

            MenusChanged::dispatch();

            return $menu->load('items');
        });
    }

    /** @param  list<MenuItemData>  $items */
    private function insert(int $menuId, ?int $parentId, array $items): void
    {
        foreach ($items as $position => $item) {
            $row = MenuItem::query()->create([
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'position' => $position,
                'label' => trim($item->label),
                'label_bn' => $item->labelBn !== null && trim($item->labelBn) !== '' ? trim($item->labelBn) : null,
                'link_type' => $item->linkType,
                'link_id' => $item->linkType->needsTarget() ? $item->linkId : null,
                'url' => $item->linkType === MenuLinkType::Custom ? $item->url : null,
                'is_visible' => $item->isVisible,
                'open_in_new_tab' => $item->openInNewTab,
            ]);

            $this->insert($menuId, $row->id, $item->children);
        }
    }

    /** @param  list<MenuItemData>  $items */
    private static function count(array $items, int $depth, int $maxDepth): int
    {
        $n = 0;
        foreach ($items as $item) {
            if ($depth > $maxDepth) {
                throw new InvalidArgumentException("Menu items nest at most {$maxDepth} levels here.");
            }
            $n += 1 + self::count($item->children, $depth + 1, $maxDepth);
        }

        return $n;
    }
}
