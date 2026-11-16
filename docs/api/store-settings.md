# Store settings API — Business, Site & branding, Branches & warehouses

Admin → Settings › Store (Figma B · 38 `241:50827`). All routes are under `/api/v1/admin`,
behind the admin Sanctum cookie session (CSRF on writes). Reads need `settings.view`, writes
`settings.update`. Errors are RFC 9457 problems (`validation-error` 422, `forbidden` 403,
`conflict` 409). Each route has a 403 role-matrix test (`tests/Feature/StoreSettings`).

## Business

| Method | Path | Permission |
|---|---|---|
| GET | `/settings/business` | settings.view |
| PUT | `/settings/business` | settings.update |

Body (whole section; `null` or blank removes the saved value):
`name_en`, `name_bn`, `trade_licence`, `bin`, `hotline`, `whatsapp`.

**One value, two screens.** `name_en`, `name_bn`, `trade_licence`, `bin` and `hotline` are the
`settings` rows `invoice.company.*` that `GET/PUT /settings/invoice` (Invoice details, now the
"Taxes & invoices" section) reads and writes, through `App\Domain\Invoicing\Services\CompanyDetails`.
There is no second copy: a save on either endpoint is visible on the other. The Business save
touches only its own keys (address, logo, footer stay); the invoice save replaces every
`CompanyDetails::FIELDS` key but never the WhatsApp key.

- `whatsapp` is a Bangladeshi mobile, 013–019 only (CLAUDE.md rule 30, `App\Support\PhoneNumber`),
  stored and returned as E.164 under the key `store.whatsapp`.
- `hotline` keeps the invoice rule (`^\+?[0-9 ()-]{5,32}$`) because a 09xxx hotline is not a mobile.
- `bin` keeps the invoice rule (`^[0-9-]{5,32}$`).

Response extras: `default_names` (what invoices print while a name is unset — the brand),
`hotline_default` (env `STORE_HOTLINE`, which order messages still use), `placeholders` (Business
fields an invoice still prints as "[placeholder]"). Values are placeholders until the owner
supplies real ones (UI-UX-DESIGN-SPEC §10).

## Site & branding

Figma B · 38 Store settings · Premium (`390:69035`, mobile `396:69446`).

| Method | Path | Permission |
|---|---|---|
| GET | `/settings/branding` | settings.view |
| PUT | `/settings/branding` | settings.update |

Body (whole section; `null` or blank removes the saved value) — request schema
`UpdateStoreBrandingRequest`:

| Field | Rule |
|---|---|
| `site_name_en`, `site_name_bn` | string ≤ 60 characters. Blank: the storefront uses the business name. |
| `tagline_en`, `tagline_bn` | string ≤ 80 |
| `description_en`, `description_bn` | string ≤ 160 (search & social description; Google shows ~155) |
| `logo_media_id` | media id. **The same row as `invoice.company.logo_media_id`** — one logo for the storefront header, invoices and emails. A save here shows on `/settings/invoice` and back. |
| `logo_dark_media_id` | media id (optional dark-background logo) |
| `icon_media_id` | media id; square (width and height within 1% of the longer side) and ≥ 512 × 512 |
| `share_image_media_id` | media id; ≥ 1200 px wide at 1.91:1 (± 3%), e.g. 1200 × 630 |

Every media id must be an existing image in the media library whose renditions are `ready`.
Otherwise 422 `validation-error` on that field with a translated message
(`lang/{en,bn}/store_settings.php`, `branding_*`: missing, not an image, still processing, icon
not square, icon too small, share image too narrow, share image wrong ratio). Widths and heights
are the media row's (the stored master's).

Response `StoreBranding`:

```
values:          the saved values above, null when unset
media:           { logo, logo_dark, icon, share_image } — each null or
                 { id, filename, width, height, url }  (url ≈ 320 px rendition, null until ready)
fallbacks:       { site_name_en, site_name_bn } — the business name (Store settings › Business,
                 else the brand name) the storefront uses while a site name is blank
storefront_host: host of STOREFRONT_URL (production domain when unset), for the preview's address bar
limits:          { site_name: 60, tagline: 80, description: 160 }
```

**Storage.** `settings` group `store`: `store.branding.{site_name,tagline,description}_{en,bn}`,
`store.branding.logo_dark_media_id`, `store.branding.icon_media_id`,
`store.branding.share_image_media_id`; the logo stays `invoice.company.logo_media_id`. Saved by
`App\Domain\Invoicing\Actions\UpdateStoreBranding` (one transaction, DB writes only; `updated_by`
on each row, as every store-settings save). Read through `App\Domain\Invoicing\Services\StoreBranding`.

**After a save** (queued, after commit): `RevalidateStorefrontTags(['store-info'])`. When the
icon changed to a new image, `App\Domain\Invoicing\Jobs\RenderStoreFavicons` (queue `media.queue`,
default `index`) renders PNG favicons 32, 180, 192 and 512 px (centre-cropped square) from the
icon's private master into a new random directory `branding/icons/{ulid}/icon-{size}.png` on the
media disk, records `{media_id, paths}` under `store.branding.icon_pngs` (only if that image is
still the chosen icon), and a chained `RevalidateStorefrontTags(['store-info'])` purges again.
A changed or removed icon drops the old `icon_pngs` row at once. Old favicon files are left in
place (paths are immutable and may be cached).

**Media library.** The dark logo, icon and share image count as usages (`type: store_branding`,
`BrandingMediaUsages`), like the invoice logo, so the library refuses to delete them while chosen.

### Storefront: `GET /api/v1/storefront/store-info` (additive)

All nullable:

```
site_name_en, site_name_bn, tagline_en, tagline_bn, description_en, description_bn
logo:        { url, width, height } | null   — ~480 px rendition (smaller if the source is)
logo_dark:   { url, width, height } | null   — same
share_image: { url, width, height } | null   — the 1200 px rendition
icons:       { png32, png180, png192, png512 } | null — absolute URLs; null until the job ran
```

`width`/`height` are the rendition's pixel size. An image whose media row is gone or not ready
is null. `site_name_*` is the saved value only — the storefront falls back to `name_en`/`name_bn`.

## Branches & warehouses (stock locations)

| Method | Path | Permission |
|---|---|---|
| GET | `/settings/locations` | settings.view |
| GET | `/settings/locations/districts` | settings.view |
| POST | `/settings/locations` | settings.update (201) |
| PUT | `/settings/locations/{location}` | settings.update |

A location is a `stock_locations` row (`App\Domain\Inventory\Models\StockLocation`). Fields:
`code` (create only; `^[a-z0-9-]+$`, unique, lower-cased), `name`, `name_bn`, `type`
(`warehouse` | `retail` | `pickup`), address per rule 31 — `district_id` (division is taken
from the district), `upazila_id` (optional, must belong to the district), `area`,
`address_line`, `landmark` — `notes` (the facts line) and `is_active`.

- Writes go through `CreateStockLocation` / `UpdateStockLocation` (one transaction each).
- **Never deleted** (no DELETE route): movements are ledger history. Switch off with
  `is_active: false`. Refused with 409 `conflict` for the default location, or one whose
  `stock_levels` still hold, reserve or expect units. Inactive locations are already refused by
  transfers, receipts and adjustments and left out of the inventory overview.
- The list returns every location (default first, then active), with `holds_stock`.
- `/settings/locations/districts` returns the 64 districts with division and upazilas for the
  address picker.

### Branch setup: single shop or multiple branches

| Method | Path | Permission |
|---|---|---|
| GET | `/settings/branch-mode` | settings.view |
| PUT | `/settings/branch-mode` | settings.update |

Owner request (2026-11-16). `store.multi_branch` (bool, group `store`), read through
`App\Domain\Inventory\Services\BranchMode`. Unset, the mode follows the data: more than one
active location = multi, so the demo seed (three branches) is multi and a fresh store a single
shop. Resource `BranchMode`: `multi_branch`, `saved` (false until the owner chooses),
`active_locations`, `default_location` `{id, name, name_bn}|null`, `blockers` (active locations
other than the default). Body: `{ "multi_branch": bool }`.

**Invariant: single mode ⇔ at most one active location.**
- `UpdateBranchMode` refuses single with 409 `branch-mode-blocked` while other locations are
  active; the problem carries `blockers[]` (`{id, name, name_bn}`). Switch them off first —
  which itself needs them empty of stock (above), so no unit leaves a count.
- `CreateStockLocation` (an active one) and `UpdateStockLocation` switching a location back on
  answer 409 `single-branch-mode` while single. Editing the shop itself, or a switched-off
  location, still works.
- All three lock the active `stock_locations` rows in ascending `id` before reading the mode,
  so "add a branch" and "go single" cannot both pass.
- Nothing else is gated: every per-branch control in the admin (inventory location cards and
  transfers, the location pickers on purchases, receipts and adjustments, the till's branch)
  already shows only when more than one location is active.

Columns added by `2026_12_06_300000_add_profile_columns_to_stock_locations_table` — expand-only,
all nullable, no backfill.
