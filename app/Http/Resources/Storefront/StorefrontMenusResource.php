<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Content\Enums\MenuLocation;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Services\MenuTree;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Every published menu, keyed by location; `null` for a location with no published menu — the
 * storefront then falls back to its category-tree menu. Items are already filtered (hidden or
 * dead targets dropped) and resolved to locale-free paths. `type: category_tree` asks the
 * storefront to render the category mega menu in that slot.
 *
 * @property Collection<int, Menu> $resource
 */
#[SchemaName('StorefrontMenus')]
final class StorefrontMenusResource extends ApiResource
{
    /**
     * @return array{header: array{items: list<array{label: string, label_bn: string|null, type: string, href: string|null, open_in_new_tab: bool, children: list<mixed>}>}|null, footer: array{items: list<array{label: string, label_bn: string|null, type: string, href: string|null, open_in_new_tab: bool, children: list<mixed>}>}|null, mobile: array{items: list<array{label: string, label_bn: string|null, type: string, href: string|null, open_in_new_tab: bool, children: list<mixed>}>}|null}
     */
    public function toArray(Request $request): array
    {
        $tree = app(MenuTree::class);
        $out = ['header' => null, 'footer' => null, 'mobile' => null];

        foreach ($this->resource as $menu) {
            if ($menu->is_published) {
                $out[$menu->location->value] = ['items' => $tree->storefront($menu)];
            }
        }

        foreach (MenuLocation::cases() as $location) {
            $out[$location->value] ??= null;
        }

        return $out;
    }
}
