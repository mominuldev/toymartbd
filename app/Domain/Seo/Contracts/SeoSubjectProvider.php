<?php

declare(strict_types=1);

namespace App\Domain\Seo\Contracts;

/**
 * Plugs one entity type into the SEO module (docs/api/seo.md §1): the per-entity SEO
 * panel, the storefront SEO document, the sitemap and the redirect conflict checks.
 * Register an implementation with `$app->tag([Impl::class], SeoSubjectProvider::TAG)`.
 */
interface SeoSubjectProvider
{
    public const string TAG = 'seo.subjects';

    /** The morph key: product, category, brand, page, blog_post, … */
    public function type(): string;

    /** Any status (the admin panel edits drafts too). */
    public function find(int $id): ?SeoSubject;

    /** Any status. */
    public function findBySlug(string $slug): ?SeoSubject;

    /**
     * Every live subject, for the sitemap. Lazily — a catalogue may hold 100k rows.
     *
     * @return iterable<SeoSubject>
     */
    public function live(): iterable;

    /**
     * Which of these locale-independent paths (`/products/x`) are live pages of this type
     * right now (redirect conflict checks, redirect-map pruning). Ignore paths that are
     * not yours.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function livePaths(array $paths): array;
}
