# Store settings · Premium pass + Site & branding (Phase 10, 2026-11-16, 3 parallel agents)

Branch `phase-10/store-settings-premium` is checked out. Repo `/Users/mominul/Sites/toymartbd`. **Two other agents edit this same working tree at the same time.** Everything in `brief-p10-common.md` applies (machine, no git, shared-file etiquette, tests, Figma read-only, final message). Overrides: **migrations: none expected** (settings live in the `settings` table); if you truly need one, use `2026_12_05_NNNNNN_*` in your N-block. Ports: API agent 8341, admin agent 8342, storefront agent 3341/8343.

## The design
Figma file `PWZp5VzEgEEf5N2Y38lGIh`, page `07 Admin — Desktop`, frame group `390:69032` "Store settings" (inside "Premium pass — KPI card, Inventory, Orders (2026-11-16)"):
- `390:69035` **B · 38 Store settings · Premium** — desktop 1440.
- `396:69446` **B · 38 Store settings · Premium · 390** — mobile 390.
Changes against today's `/settings/*` (B · 38 `241:50827`, `resources/js/admin/features/storeSettings/`):
1. Section nav is a **card** (`390:69048`): group label "STORE", a Phosphor icon per item (buildings, storefront, warehouse, list-checks, truck, file-text, cash-register), a "New" badge on Site & branding.
2. New section **Site & branding** (`/settings/branding`, second in the nav) with a section header + "Live on the storefront" StatusChip, a 544-wide main column and a right **Live preview** rail (EN/বাংলা toggle; browser tab, storefront header, shared-on-WhatsApp card):
   - **Store identity**: Store name EN/BN (max 60, "Blank uses the business name"), Tagline EN/BN (max 80), Search & social description EN/BN (max 160, "Google shows about 155"), each with a live character counter.
   - **Logo & icons**: four upload tiles — Logo (light backgrounds; header, invoices, emails), Dark-background logo (optional), Store icon & favicon (square ≥512, previews at 64/40/24/16), Social share image (1200 × 630, 1.91:1). Each: Replace (upload) / Library (media picker) / Remove; empty state is a dropzone. Note: "PNG, JPG or WebP up to 10 MB. SVG is not accepted…".
   - **Brand colours** (read-only): Brand, Accent, Teal, Pink, Canvas swatches with token names, from the storefront design tokens. Not editable, on purpose.
3. A **sticky save bar** at the bottom of the content: "Unsaved changes in Site & branding · <what changed>" + Discard + Save changes.

## API contract (agent A builds it; B and C code against it exactly)
`GET /api/v1/admin/settings/branding` (settings.view) and `PUT /api/v1/admin/settings/branding` (settings.update). Resource name **`StoreBranding`**, request **`UpdateStoreBrandingRequest`** (so generated types are `Schema<'StoreBranding'>` and `UpdateStoreBrandingRequestInput`).

PUT body (whole section; null/empty clears):
```
site_name_en, site_name_bn          string|null  max 60
tagline_en, tagline_bn              string|null  max 80
description_en, description_bn      string|null  max 160
logo_media_id                       int|null   — the SAME row as invoice.company.logo_media_id (one logo: header, invoices, emails)
logo_dark_media_id                  int|null
icon_media_id                       int|null   — square (|w−h| ≤ 1%), ≥ 512×512
share_image_media_id                int|null   — ≥ 1200 wide, ratio 1.91:1 ± 3%
```
Every media id must be an existing, Ready, image Media row (422 otherwise, with a field-specific translated message). Storage: `settings` group `store`, keys `store.branding.*` (logo stays `invoice.company.logo_media_id`).

GET response `StoreBranding`:
```
values: { site_name_en, site_name_bn, tagline_en, tagline_bn, description_en, description_bn,
          logo_media_id, logo_dark_media_id, icon_media_id, share_image_media_id }   (saved values, null when unset)
media:  { logo, logo_dark, icon, share_image }  each null | { id, filename, width, height, url (≈320px rendition, null until ready) }
fallbacks: { site_name_en, site_name_bn }   — the business name (CompanyDetails, defaulted) the storefront uses when site_name is blank
storefront_host: string|null                — e.g. "toymartbd.com" from config (app/storefront URL), for the preview's address bar
limits: { site_name: 60, tagline: 80, description: 160 }
```
On save: audit like other settings saves, and dispatch `RevalidateStorefrontTags` for `store-info` (queued, after commit). If the icon changed, a queued job (after commit, `index` or default queue — follow existing media jobs) renders PNG favicons 32/180/192/512 from the media master into a new random path on the media disk and stores the paths under `store.branding.icon_pngs`; it then purges `store-info` again. No HTTP calls, nothing inside a transaction but DB writes.

`GET /api/v1/storefront/store-info` gains (additive, rule 13), all nullable:
```
site_name_en, site_name_bn, tagline_en, tagline_bn, description_en, description_bn,
logo: {url, width, height}|null, logo_dark: {...}|null, share_image: {url, width, height}|null,
icons: { png32, png180, png192, png512 } | null   (absolute URLs; null until the job ran)
```
`logo`/`logo_dark` url = a ~480px rendition; `share_image` url = the 1200 rendition.

## Agents and scopes
| # | Scope | Files |
|---|---|---|
| A — API | Everything Laravel: DTO + `UpdateStoreBranding` Action (Domain), FormRequest, Resource, `StoreBrandingController`, routes in `routes/api_v1_admin_store_settings.php`, role-matrix entries (`tests/Support/role-matrix-routes.php`), favicon job, StoreInfoController/StoreInfoResource additions, `lang/{en,bn}/store_settings.php` messages, Pest tests (validation incl. non-square icon, wrong-ratio share image, non-ready/ non-image media, 403s, invoice logo shared, store-info fields, job output), `composer openapi:export` + `npm run api-types:generate`, `docs/api/store-settings.md` section. | `app/`, `routes/`, `lang/`, `tests/`, `docs/api/`, `docs/api/openapi.json`, `packages/api-types/` |
| B — Admin | Nav card + icons + New badge (`SettingsLayout.tsx`), `/settings/branding` route (admin `app/routes.tsx` — surgical edit), `SiteBrandingSection.tsx` + its parts (upload tile using the existing media picker `features/media/picker` and the existing upload API — look at `content/shared/MediaField.tsx`, `invoices/InvoiceSettingsPage.tsx`), live preview rail, read-only brand colours (values from `packages/tokens` storefront theme — semantic token names, no hex in components), shared `StickySaveBar` in `storeSettings/parts.tsx` used by **every** section (owner, 2026-11-16): page-level forms drop their own Save buttons; dialogs keep theirs, messages EN+BN in `messages.storeSettings.ts` (or the file that holds `storeSettings.*`), API hooks in `storeSettings/brandingApi.ts` typed from `Schema<'StoreBranding'>` / `UpdateStoreBrandingRequestInput` (agent A generates them — if they are not there yet when you typecheck, wait and re-check; never hand-write the types), Vitest tests, screenshots vs both frames at 1440 and 390. The current invoice logo picker stays working. | `resources/js/admin/features/storeSettings/**`, admin route file + i18n messages (surgical) |
| C — Storefront | `lib/store-info.ts` parses the new fields (unknown → narrowed, never throws); `app/[locale]/layout.tsx` `generateMetadata` uses site name (fallback: business name, then messages), tagline/description per locale, `openGraph.images` default = share image, `icons` from `icons.*` (fallback: current static icons, if any); header logo (`components/layout/SiteHeader.tsx`) shows the uploaded logo image when set (with width/height, alt = store name), else today's wordmark; footer may use `logo_dark` only if it sits on a dark background — check. Mock API fixtures for E2E (`app/api` / `mock`) updated with Bangla + English values. Vitest unit tests for parsing + metadata. `npm run typecheck:storefront`, `npm run test:storefront`; storefront Playwright only the specs you touch, on your own ports. | `toymartbd-app/storefront/**` |
