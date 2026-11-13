<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/**
 * What a menu item points at. Entity links store the id and resolve the URL on read;
 * `custom` stores a URL; `category_tree` expands to the category mega menu (the menu
 * the storefront had before the builder); `none` is a heading without a link.
 */
enum MenuLinkType: string
{
    case Category = 'category';
    case Brand = 'brand';
    case Page = 'page';
    case Post = 'post';
    case Product = 'product';
    case Custom = 'custom';
    case CategoryTree = 'category_tree';
    case None = 'none';

    public function needsTarget(): bool
    {
        return in_array($this, [self::Category, self::Brand, self::Page, self::Post, self::Product], true);
    }
}
