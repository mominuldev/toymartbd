<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\DTOs\MenuItemData;
use App\Domain\Content\Enums\MenuLinkType;
use App\Domain\Content\Enums\MenuLocation;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Models\Menu;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /content/menus/{location}` — the whole menu: `is_published` and the item tree in display
 * order (`items[].children[]`). Nesting is capped per location (header 3 levels, footer and
 * mobile 2) and a menu holds at most 120 items. Entity links (`category`, `brand`, `page`,
 * `post`, `product`) need an existing `link_id`; `custom` needs `url` — a site path (`/…`) or
 * an https URL; `category_tree` and `none` take neither.
 */
final class SaveMenuRequest extends ApiFormRequest
{
    /** The deepest any location allows; deeper levels are refused structurally. */
    private const int MAX_LEVELS = 3;

    public function authorize(): bool
    {
        $menu = Menu::query()->where('location', $this->location()->value)->first();

        return $menu === null
            ? ($this->user()?->can('create', Menu::class) ?? false)
            : ($this->user()?->can('update', $menu) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['is_published' => ['required', 'boolean']];
        $prefix = 'items';

        for ($level = 1; $level <= self::MAX_LEVELS; $level++) {
            $rules[$prefix] = [$level === 1 ? 'present' : 'sometimes', 'array', 'max:'.$this->location()->maxItems()];
            $rules[$prefix.'.*'] = ['array:label,label_bn,link_type,link_id,url,is_visible,open_in_new_tab,children'];
            $rules[$prefix.'.*.label'] = ['required', 'string', 'max:80'];
            $rules[$prefix.'.*.label_bn'] = ['nullable', 'string', 'max:80'];
            $rules[$prefix.'.*.link_type'] = ['required', Rule::enum(MenuLinkType::class)];
            $rules[$prefix.'.*.link_id'] = ['nullable', 'integer', 'min:1'];
            $rules[$prefix.'.*.url'] = ['nullable', 'string', 'max:2048'];
            $rules[$prefix.'.*.is_visible'] = ['sometimes', 'boolean'];
            $rules[$prefix.'.*.open_in_new_tab'] = ['sometimes', 'boolean'];
            $prefix .= '.*.children';
        }

        return $rules;
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $items = $this->input('items', []);
            $location = $this->location();
            $count = 0;
            $wanted = [];
            $this->walk(is_array($items) ? $items : [], 'items', 1, $location, $validator, $count, $wanted);

            if ($count > $location->maxItems()) {
                $validator->errors()->add('items', "A menu holds at most {$location->maxItems()} items.");
            }

            foreach ($wanted as $type => $refs) {
                $found = $this->existing(MenuLinkType::from($type), array_values(array_unique(array_column($refs, 'id'))));
                foreach ($refs as $ref) {
                    if (! in_array($ref['id'], $found, true)) {
                        $validator->errors()->add($ref['field'], "No {$type} has this id.");
                    }
                }
            }
        }];
    }

    /**
     * @param  array<mixed>  $items
     * @param  array<string, list<array{id: int, field: string}>>  $wanted
     */
    private function walk(array $items, string $path, int $depth, MenuLocation $location, Validator $validator, int &$count, array &$wanted): void
    {
        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $at = "{$path}.{$i}";
            $count++;

            if ($depth > $location->maxDepth()) {
                $validator->errors()->add($at, "Items nest at most {$location->maxDepth()} levels in the {$location->value} menu.");

                continue;
            }

            $type = MenuLinkType::from((string) $item['link_type']);
            $linkId = $item['link_id'] ?? null;
            $url = $item['url'] ?? null;

            if ($type->needsTarget()) {
                if ($linkId === null) {
                    $validator->errors()->add("{$at}.link_id", 'Choose what this item links to.');
                } else {
                    $wanted[$type->value][] = ['id' => (int) $linkId, 'field' => "{$at}.link_id"];
                }
            }

            if ($type === MenuLinkType::Custom && (! is_string($url) || ! self::validUrl($url))) {
                $validator->errors()->add("{$at}.url", 'Enter a site path starting with / or a full https:// address.');
            }

            if ($type === MenuLinkType::CategoryTree && $depth !== 1) {
                $validator->errors()->add("{$at}.link_type", 'The category menu can only be a top-level item.');
            }

            $children = $item['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $this->walk($children, "{$at}.children", $depth + 1, $location, $validator, $count, $wanted);
            }
        }
    }

    public static function validUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//') && preg_match('#^/[A-Za-z0-9\-._~/?=&%\#]*$#', $url) === 1;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://');
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function existing(MenuLinkType $type, array $ids): array
    {
        $query = match ($type) {
            MenuLinkType::Category => Category::query(),
            MenuLinkType::Brand => Brand::query(),
            MenuLinkType::Product => Product::query(),
            MenuLinkType::Page => CmsPage::query(),
            MenuLinkType::Post => BlogPost::query(),
            default => null,
        };

        return $query === null ? [] : $query->whereKey($ids)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    public function location(): MenuLocation
    {
        $location = $this->route('location');

        return $location instanceof MenuLocation ? $location : MenuLocation::from((string) $location);
    }

    public function isPublished(): bool
    {
        return $this->boolean('is_published');
    }

    /** @return list<MenuItemData> */
    public function items(): array
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        return self::toItems(is_array($v['items'] ?? null) ? $v['items'] : []);
    }

    /**
     * @param  array<mixed>  $items
     * @return list<MenuItemData>
     */
    private static function toItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = new MenuItemData(
                label: (string) $item['label'],
                labelBn: is_string($item['label_bn'] ?? null) ? $item['label_bn'] : null,
                linkType: MenuLinkType::from((string) $item['link_type']),
                linkId: isset($item['link_id']) ? (int) $item['link_id'] : null,
                url: is_string($item['url'] ?? null) ? $item['url'] : null,
                isVisible: (bool) ($item['is_visible'] ?? true),
                openInNewTab: (bool) ($item['open_in_new_tab'] ?? false),
                children: self::toItems(is_array($item['children'] ?? null) ? $item['children'] : []),
            );
        }

        return $out;
    }
}
