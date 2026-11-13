<?php

declare(strict_types=1);

namespace App\Domain\Seo\Contracts;

/**
 * The one write path to `seo_meta` for a domain that may not depend on Seo internals
 * (deptrac layer SeoContracts) — Content's blog editor writes its post's search fields
 * through this, never the SeoMeta model. Implemented by Seo ({@see \App\Domain\Seo\Actions\SaveSeoMeta}).
 *
 * Both methods join the caller's transaction (a savepoint); the caller purges its own pages.
 */
interface SeoMetaStore
{
    /**
     * Write these `seo_meta` columns of one page; columns not given keep their value
     * (a new row starts from the defaults). Unknown keys are ignored.
     *
     * @param  array<string, mixed>  $columns
     */
    public function merge(string $type, int $id, array $columns): void;

    /** Remove the page's `seo_meta` row (the page itself is being deleted). */
    public function forget(string $type, int $id): void;
}
