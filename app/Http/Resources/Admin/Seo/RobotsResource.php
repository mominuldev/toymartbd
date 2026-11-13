<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Seo;

use App\Domain\Seo\Services\RobotsTxt;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * robots.txt editor state: what is stored (the default when never saved), the default,
 * and exactly what the storefront serves (the sitemap line guaranteed).
 */
#[SchemaName('RobotsTxt')]
final class RobotsResource extends ApiResource
{
    public function __construct()
    {
        parent::__construct(null);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $stored = RobotsTxt::stored();

        return [
            'content' => $stored ?? RobotsTxt::defaultContent(),
            'is_default' => $stored === null,
            'default_content' => RobotsTxt::defaultContent(),
            'sitemap_url' => RobotsTxt::sitemapUrl(),
            'served' => RobotsTxt::served(),
        ];
    }
}
