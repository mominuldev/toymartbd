<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Seo\Contracts\SeoSubjectProvider;
use Illuminate\Contracts\Container\Container;

/**
 * The registry of {@see SeoSubjectProvider}s (container tag `seo.subjects`). Products,
 * categories and brands register in SeoServiceProvider; CMS pages, posts and guides
 * register their own.
 */
final class SeoSubjects
{
    /** @var array<string, SeoSubjectProvider>|null */
    private ?array $providers = null;

    public function __construct(private readonly Container $container) {}

    public function for(string $type): ?SeoSubjectProvider
    {
        return $this->all()[$type] ?? null;
    }

    /** @return array<string, SeoSubjectProvider> keyed by type */
    public function all(): array
    {
        if ($this->providers === null) {
            $this->providers = [];
            foreach ($this->container->tagged(SeoSubjectProvider::TAG) as $provider) {
                if ($provider instanceof SeoSubjectProvider) {
                    $this->providers[$provider->type()] = $provider;
                }
            }
        }

        return $this->providers;
    }
}
