<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Content\Enums\MenuLocation;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Services\MenuTree;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Content\ContentFormat;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One location's menu in the editor. A location never saved answers with `exists: false` and
 * no items (the storefront then uses the category-tree fallback). Each item carries its
 * resolved `target` (null for custom URLs, headings and the category menu); `target.live`
 * false means the storefront hides it.
 *
 * @property Menu $resource
 */
#[SchemaName('Menu')]
final class MenuResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $menu = $this->resource;
        $location = $menu->location;

        return [
            /** @var 'header'|'footer'|'mobile' */
            'location' => $location->value,
            /** @var bool */
            'exists' => $menu->exists,
            'is_published' => $menu->is_published,
            /** @var string|null */
            'published_at' => ContentFormat::utc($menu->published_at),
            /** @var string|null */
            'updated_at' => ContentFormat::utc($menu->updated_at),
            'max_depth' => $location->maxDepth(),
            'max_items' => $location->maxItems(),
            /**
             * @var list<array{id: int, label: string, label_bn: string|null, link_type: string, link_id: int|null, url: string|null, is_visible: bool, open_in_new_tab: bool, target: array{label: string, label_bn: string|null, path: string, live: bool}|null, children: list<mixed>}>
             */
            'items' => $menu->exists ? app(MenuTree::class)->admin($menu) : [],
        ];
    }

    public static function forLocation(MenuLocation $location): self
    {
        $menu = Menu::query()->with('items')->where('location', $location->value)->first() ?? new Menu(['location' => $location]);

        return new self($menu);
    }
}
