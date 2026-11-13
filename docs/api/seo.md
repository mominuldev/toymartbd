# SEO plumbing (Phase 7 — S-01…S-04, P-06c)

Owner of this contract: the SEO module (`app/Domain/Seo`, `toymartbd-app/storefront/lib/seo/**`,
`resources/js/admin/features/seo/**`). Every storefront template and every content entity uses
these helpers; nothing builds its own `<head>`, JSON-LD, sitemap entry or redirect.

## 1. Entity types

`seo_meta.seoable_type` and `slug_history.sluggable_type` hold a short morph key, never a class name:

| type | storefront path (locale-independent) | provider (`app/Domain/Seo/Subjects/`) |
|---|---|---|
| `product` | `/products/{slug}` | `ProductSubjects` — live = active, published, online-store channel |
| `category` | `/categories/{slug}` | `CategorySubjects` — live = active |
| `brand` | `/brands/{slug}` | `BrandSubjects` — live = active |
| `page` | `/pages/{slug}` | `PageSubjects` (CMS `CmsPage::MORPH`) — live = published, `published_at` reached |
| `blog_post` | `/blog/{slug}` (article) · `/guides/{slug}` (buying guide) | `BlogPostSubjects` (`BlogPost::SEO_TYPE`) |

`blog_category`, `blog_tag` and `blog_author` rows exist in `seo_meta` (written by the blog's own
Actions) but have no provider yet, so they are not in the generic panel or the sitemap.
Bangla is always the same path under `/bn` (rule 37). The path a provider returns is the truth;
the table is the default.

### Plugging a new entity in (pages, posts, guides)

`App\Domain\Seo\Contracts\*` is its own deptrac layer (`SeoContracts`) that every domain may use.

```php
use App\Domain\Seo\Contracts\SeoSubject;          // readonly DTO
use App\Domain\Seo\Contracts\SeoSubjectProvider;  // interface

final class PageSeoSubjects implements SeoSubjectProvider
{
    public function type(): string { return 'page'; }
    public function find(int $id): ?SeoSubject { ... }            // any status (admin panel)
    public function findBySlug(string $slug): ?SeoSubject { ... } // any status
    public function live(): iterable { ... }                      // published only — feeds the sitemap
    public function liveSlugs(array $slugs): array { ... }        // which of these slugs are live now
}

// SeoSubject(type, id, slug, path, name, nameBn, description, descriptionBn, image, isLive, updatedAt)
```

Register it in a service provider: `$this->app->tag([PageSeoSubjects::class], SeoSubjectProvider::TAG);`

**Slug changes.** Dispatch `App\Domain\Seo\Events\SlugChanged($type, $id, $oldSlug, $newSlug, $wasLive, $actorId)`
from your Action when a slug changes (it implements `ShouldDispatchAfterCommit`). The Seo listener
writes `slug_history` (only when `$wasLive`), re-points older rows so a redirect is always one
hop, and deletes any row whose `old_slug` is now live again. Products keep writing it in their own
transaction (`SaveProductWithSeo`); categories and brands are recorded from `CategoryChanged` /
`BrandChanged` (`previousSlug`).

## 2. Admin API (`/api/v1/admin/seo/*`, `seo.view` to read, `seo.manage` to write)

| Method | Path | |
|---|---|---|
| GET | `/seo/meta/{type}/{id}` | `SeoDocument`: stored overrides (null = unset), server defaults, both-locale SERP previews, URLs |
| PUT | `/seo/meta/{type}/{id}` | save the whole document (`SaveSeoMetaRequest`); returns `SeoDocument`; revalidates the page |
| POST | `/seo/serp-preview` | `{title, description, url, locale}` → measured + truncated preview for unsaved text |
| GET | `/seo/redirects` | manual redirects; `filter[q]`, `filter[status_code]`, `filter[is_active]`, `sort` ∈ `from_path,-hit_count,-last_hit_at,-created_at` |
| POST | `/seo/redirects` | create (conflict-checked, chain-collapsed) |
| PATCH | `/seo/redirects/{id}` | update |
| DELETE | `/seo/redirects/{id}` | delete |
| POST | `/seo/redirects/import` | CSV `from_path,to_path,status_code[,note]` (multipart `file`, ≤ 2,000 rows); all-or-nothing, row errors returned |
| GET | `/seo/slug-history` | automatic redirects from slug changes (read-only); `filter[type]` |
| GET | `/seo/sitemap` | last build: files (type, locale, part, url count), generated_at, errors |
| POST | `/seo/sitemap/regenerate` | queue a rebuild (202) |
| GET | `/seo/robots` | `{content, default_content, sitemap_url, served}` |
| PUT | `/seo/robots` | `{content}` — validated directive by directive |

`SeoDocument` fields: `type, id, slug, path, urls{en,bn}, is_live`, the stored overrides
(`title, title_bn, description, description_bn, og_title, og_title_bn, og_description,
og_description_bn, og_image, canonical_url, robots, sitemap_exclude, twitter_card,
focus_keyword`), `defaults{title,title_bn,description,description_bn,og_image}`,
`serp{en,bn}` (each `{title, title_display, title_px, title_max, title_truncated, description,
description_display, description_px, description_max, description_truncated, url_display}`).
Truncation is computed on the server at Google's desktop pixel metric (`SerpMeasure`), never in the client.

### Redirect rules (enforced in `App\Domain\Seo\Actions\SaveRedirect`)

- Paths are locale-independent: `/bn/x` is stored as `/x` and the redirect applies in both locales.
- `from_path` must start with `/`, no query or fragment, ≤ 512 chars, and must not be a **live** URL
  (a live product/category/brand/page/post/guide, or a reserved route such as `/`, `/cart`, `/checkout`).
- `to_path` is a path starting with `/` or an absolute `https://` URL; `from_path ≠ to_path`.
- **No loops:** following the target through existing redirects must not reach `from_path`.
- **No chains:** a target that is itself redirected is stored as its final target, and redirects
  that pointed at the new `from_path` are re-pointed to the new target. A → B → C is stored A → C.
- `status_code` ∈ 301 | 302. `hit_count` / `last_hit_at` count storefront hits.

## 3. Storefront API (`/api/v1/storefront/*`, `storefront:read`)

| Method | Path | Cache tags (storefront) |
|---|---|---|
| GET | `/seo/{type}/{slug}` | `seo`, `{type}:{slug}` (e.g. `product:x`, `page:x`, `blog_post:x`) — effective SEO for **both** locales (404 when not live) |
| GET | `/redirects` | `redirects` — the whole collapsed map `{redirects: [{id, from, to, status}]}` |
| POST | `/redirects/{id}/hit` | — counts one hit (fire-and-forget from the proxy) |
| GET | `/sitemaps/{file}` | `sitemaps` — `sitemap.xml` (index) or `sitemap-{type}-{en|bn}-{n}.xml` |
| GET | `/robots` | `robots` — `text/plain` |

`GET /seo/{type}/{slug}` →
```json
{ "type": "product", "slug": "lego-city-fire-truck", "path": "/products/lego-city-fire-truck",
  "robots": { "index": true, "follow": true }, "canonical_url": null, "sitemap_exclude": false,
  "twitter_card": "summary_large_image", "og_image": "https://…/1200.webp",
  "en": { "title": "…", "description": "…", "og_title": "…", "og_description": "…" },
  "bn": { "title": "…", "description": "…", "og_title": "…", "og_description": "…" },
  "updated_at": "2026-11-28T10:00:00+06:00" }
```
Every text value is already the effective one (override, else the server default). `title` is a
complete `<title>` (it already carries the site name) — use it as `title.absolute`.

## 4. Storefront helpers (`toymartbd-app/storefront/lib/seo/`)

- `metadata.ts` — `buildMetadata(input)` plus one function per template: `homeMetadata`,
  `listingPageMetadata` (category / brand / toys, pagination + facets), `productMetadata`,
  `contentMetadata` (page / post / guide), `searchMetadata` (noindex), `privateMetadata`
  (cart / checkout / account / order pages — noindex), `trackMetadata`. Every result carries
  canonical, `hreflang` en + bn + `x-default`, robots, Open Graph (per-locale title/description,
  `og:locale` `en_US` / `bn_BD` + alternate) and Twitter.
- `jsonld.ts` — `productJsonLd`, `breadcrumbJsonLd`, `organizationJsonLd`, `websiteJsonLd`
  (with `SearchAction`), `articleJsonLd`, `faqPageJsonLd`, and `JsonLd` (`components/seo/JsonLd.tsx`)
  to render them. `validate.ts` checks Google's required/recommended properties per type and is
  run against every template in the unit tests.
- `fetch.ts` — `getSeo(type, slug)` (server-only, tag-cached).
- `redirects.ts` — pure `resolveRedirect(map, pathname)` used by `proxy.ts` (301/302 in both locales).

Pages that are not wired yet carry a `// SEO: agent 4` marker.

## 5. Sitemaps and robots

- `App\Domain\Seo\Jobs\GenerateSitemaps` runs daily at 03:00 Asia/Dhaka (and on demand from
  the admin). It writes `storage/app/private/sitemaps/` atomically: an index `sitemap.xml` and
  `sitemap-{partition}-{locale}-{n}.xml` — the partition is the path's first segment (`static,
  products, categories, brands, pages, blog, guides`) × `en, bn`, chunked at 50,000 URLs. Every `<url>` has `<lastmod>` and `xhtml:link`
  alternates for `en`, `bn` and `x-default`. Excluded: not live, `robots` noindex,
  `sitemap_exclude`, a `canonical_url` override (the page canonicalises elsewhere), products not in
  the online store channel.
- The storefront serves `/sitemap.xml`, `/sitemaps/{file}` and `/robots.txt` from these
  endpoints (cached, tags `sitemaps` / `robots`). `robots.txt` always ends with
  `Sitemap: {STOREFRONT_URL}/sitemap.xml`; the admin editor accepts only `User-agent`, `Allow`,
  `Disallow`, `Sitemap`, `Crawl-delay` and comments.

## 6. Revalidation tags added

`seo` (any SEO document), `{type}:{slug}` for every type (`page:x`, `blog_post:x` alongside the catalogue's `product:x`), `redirects`, `sitemaps`, `robots`. Categories and brands keep their `category:`/`brand:` tags.
