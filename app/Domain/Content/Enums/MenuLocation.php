<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/**
 * Where a menu renders. Each location caps how deep items may nest: the header mega menu
 * has three levels (tab → column → link), the footer two (column → link), the mobile menu two.
 */
enum MenuLocation: string
{
    case Header = 'header';
    case Footer = 'footer';
    case Mobile = 'mobile';

    public function maxDepth(): int
    {
        return match ($this) {
            self::Header => 3,
            self::Footer, self::Mobile => 2,
        };
    }

    /** Most items one menu may hold, every level counted. */
    public function maxItems(): int
    {
        return 120;
    }
}
