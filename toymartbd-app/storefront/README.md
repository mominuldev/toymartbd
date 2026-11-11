# Storefront

The customer shop for Toy Mart BD: a Next.js 16 App Router app with TypeScript strict and Tailwind v4.
English is served at `/` and Bangla at `/bn/`. Catalog pages use ISR with cache tags, and the API is
only ever called from the server (CLAUDE.md rule 28).

It is the npm workspace `storefront`. Run every command **from the repo root**.

```bash
npm install                      # one install covers admin, storefront and packages/*
cp toymartbd-app/storefront/.env.example toymartbd-app/storefront/.env.local   # then fill it in

npm run dev:storefront           # http://localhost:3000
npm run build:storefront         # production build (does not need the API: pages render on first visit)
npm run start:storefront         # serve the build
npm run typecheck:storefront     # next typegen + tsc --noEmit
npm run test:storefront          # unit tests (Vitest)
npm run test:e2e:storefront      # Playwright: builds into .next-e2e against a mock API, then tests
```

## Environment

Every variable is **server-only**. Never give one a `NEXT_PUBLIC_` prefix and never add it to
`next.config.ts` `env`. The E2E bundle test fails the build if a credential reaches the browser.

| Variable | What |
|---|---|
| `API_URL` | Base URL of the Laravel API, with no trailing slash, e.g. `http://localhost:8000` |
| `STOREFRONT_API_TOKEN` | Sanctum token with the `storefront:read` ability. Mint it with `php artisan toymart:storefront-token` |
| `STOREFRONT_REVALIDATE_SECRET` | HMAC secret for `POST /api/revalidate`. Must equal the value in Laravel's `.env` |
| `SITE_URL` | Public origin, used for canonical and `hreflang` URLs. Defaults to `http://localhost:3000` |

## How it fits together

| Concern | Where |
|---|---|
| Locale routing | `proxy.ts` + `lib/locale-route.ts`. `/x` is **rewritten** (not redirected) to the internal `app/[locale]` tree as `en`. `/bn/x` is served as-is. A hand-typed `/en/x` gets a 308 to `/x`. `Accept-Language` never changes the route. |
| `<html lang>`, fonts, tokens | `app/[locale]/layout.tsx` (root layout), `lib/fonts.ts`, `app/globals.css` |
| hreflang | `lib/seo.ts` `alternatesFor(locale, path)`. Every page's `generateMetadata` uses it, so the page emits en, bn and `x-default` (pointing to en) |
| UI strings | `lib/i18n/en.ts` and `lib/i18n/bn.ts`. Every string a user sees comes from here (rule 35). The Bangla strings are drafts awaiting native review (`pendingNativeReview` in `bn.ts`) |
| Language toggle | `components/LocaleToggle.tsx`. It is a plain link to the same page in the other locale, and it remembers the choice in the `tm_locale` cookie |
| Bangla suggestion | `components/LocaleSuggestion.tsx`. On English pages it shows a dismissible banner when the browser prefers Bangla, or when the visitor chose Bangla before. It never redirects |
| API client | `lib/api.ts` (`server-only`). `cached(path, tags)` uses ISR with tags and a 3600 s ceiling. `noStore(path)` skips the cache. Errors arrive as a typed `ApiError` parsed from problem+json |
| API types | `lib/api-types.ts`. These are hand-typed for now (TODO: switch to `@toymart/api-types` once the brands schemas are generated) |
| Revalidation | `app/api/revalidate/route.ts` + `lib/revalidate.ts`. It implements `docs/api/revalidation.md` exactly |
| Catalog pages | `app/[locale]/brands` (tag `brands`) and `app/[locale]/brands/[slug]` (tags `brands`, `brand:{slug}`) |

### Adding a page

1. Create it under `app/[locale]/…` and wrap it in `<PageShell locale path>`. Pass `path` as the
   locale-independent path, e.g. `/brands/lego`.
2. Return `alternates: alternatesFor(locale, path)` from `generateMetadata`.
3. Put every string in both catalogs. `tests/i18n.test.ts` fails if the two catalogs' keys drift apart.
4. Fetch through `lib/api.ts`, and take cache tags from the vocabulary in `docs/api/revalidation.md`.

## Known limitations

- **404 HTML.** In Next 16.4.3, `notFound()` called from a page returns HTTP 404 with
  `<meta name="robots" content="noindex">`. The HTML body, though, is Next's empty `__next_error__`
  shell, and the localized not-found UI only appears once the client renders it. This also happens
  in a plain `app/layout.tsx` app. Crawlers get the right status. Visitors with JavaScript disabled
  see a blank page.
- **Fredoka has no 800 weight.** It tops out at 700, so `display-1`, `display-2` and `price-lg`
  render Latin at 700.
- **Bengali font files are over the 40 KB target.** Each is a variable file covering the weights
  the scale uses: Anek Bangla is about 156 KB and Baloo Da 2 about 90 KB. Neither is preloaded,
  and both load only when Bangla text is on the page.
