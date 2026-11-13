<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/** How the storefront lays a static page out. `faq` renders `faq_items` as an accordion. */
enum PageTemplate: string
{
    case Default = 'default';
    case Sidebar = 'sidebar';
    case Faq = 'faq';
}
