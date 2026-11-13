<?php

declare(strict_types=1);

namespace App\Domain\Seo\Jobs;

use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Services\SitemapBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Rebuild every sitemap (daily 03:00 Asia/Dhaka, SYSTEM-ARCHITECTURE §7, and on demand
 * from SEO → Sitemap & robots), then purge the storefront's cached copies.
 */
final class GenerateSitemaps implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue('index');
    }

    public function handle(SitemapBuilder $builder): void
    {
        $builder->build();

        SeoPagesStale::dispatch(['sitemaps']);
    }
}
