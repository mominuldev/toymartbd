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

Figma **B · 38 Store settings · Premium** `390:69035`. How the storefront introduces itself.

| Method | Path | Permission |
|---|---|---|
| GET | `/settings/site` | settings.view |
| PUT | `/settings/site` | settings.update |

Body (whole section; `null` or blank removes the saved value — the storefront then falls back):

| Field | Rule | Stored as |
|---|---|---|
| `name`, `name_bn` | string ≤ 60 | `store.name`, `store.name_bn` |
| `tagline`, `tagline_bn` | string ≤ 80 | `store.tagline`, `store.tagline_bn` |
| `description`, `description_bn` | string ≤ 160 | `store.description`, `store.description_bn` |
| `logo_media_id` | ready library image, ≥ 120 px wide | `store.logo_media_id` |
| `logo_dark_media_id` | ready library image, ≥ 120 px wide (optional) | `store.logo_dark_media_id` |
| `icon_media_id` | ready image, square (±1 %), ≥ 512 × 512 | `store.icon_media_id` |
| `social_image_media_id` | ready image, ratio 1.8–2.0 (1200 × 630 ideal), ≥ 600 wide | `store.social_image_media_id` |

`settings` group `store`, `updated_by` = the saver (like Business and POS). Response `SiteBranding`:
the six texts, `logo` / `logo_dark` / `icon` / `social_image` as `{ id, url (480 WebP), width, height,
filename }` or null, `fallback_names` (what a blank name shows: the business name, else the brand) and
`max_lengths`.

**Why its own endpoint, not more fields on `/settings/business`:** Business is a second view of the
invoice identity (`invoice.company.*`, shared with `/settings/invoice`); the storefront's display
name and look are not printed on invoices, and the shop's legal name and its tab name may differ.

- A save that changes anything queues `RevalidateStorefrontTags(['store-info'])` after commit.
- The four images are **media usages** (`SiteBrandingMediaUsages`, type `site_branding`, admin path
  `/admin/settings/site`): the library refuses to delete one while it is set (409).
- Brand colours are not settings: they come from `packages/tokens` and the screen shows them read-only.

### Generated files — `GET /site-assets/{media}/{name}` (web route, public)

The media pipeline makes WebP/AVIF only, which Apple touch icons and some link previewers do not
take. `App\Domain\Content\Services\SiteAssets` makes, on first request, square PNGs from the store
icon (`icon-32.png`, `icon-180.png`, `icon-192.png`, `icon-512.png`) and a 1200 × 630 JPEG from the
share image (`share.jpg`), stored beside the media's renditions (`{base}/site-{name}`, immutable
with the base, removed with the media), then answers a `302` (`Cache-Control: public, max-age=300`)
like `/m/{media}/…`. A media id that is not the current icon / share image is a 404, so the route
never converts an arbitrary library image. Throttled 240/min.

### Storefront — `GET /storefront/store-info` → `site` (additive)

`{ name_en, name_bn` (site name → business name → brand), `tagline_en, tagline_bn, description_en,
description_bn, logo, logo_dark` (`{ url, width, height }` or null), `icons` (`[{ size, url }]` for 32,
180, 192, 512 — empty until an icon is set), `social_image` (`{ url, width: 1200, height: 630 }` → the
JPEG above) `}`. The storefront renders the logo in the header (else its built-in Wordmark), uses the
name in the title template and `og:site_name`, the description (else tagline) as the default
description, `icons` as `<link rel="icon">` / `apple-touch-icon`, and the share image as the
`og:image` of any page without its own. A blank Bangla tagline / description borrows the English one.

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

Columns added by `2026_12_06_300000_add_profile_columns_to_stock_locations_table` — expand-only,
all nullable, no backfill.
