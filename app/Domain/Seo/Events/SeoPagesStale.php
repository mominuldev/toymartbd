<?php

declare(strict_types=1);

namespace App\Domain\Seo\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Storefront cache tags made stale by an SEO write (an SEO document, the redirect map,
 * the sitemaps or robots.txt). The Content listener purges them after commit
 * (docs/api/revalidation.md, docs/api/seo.md §6).
 */
final readonly class SeoPagesStale implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  list<string>  $tags */
    public function __construct(public array $tags) {}
}
