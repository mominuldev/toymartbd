# CMS blocks and the homepage builder (Phase 7)

The homepage is an ordered list of typed blocks. Editors change a **draft**; **publish** freezes the
draft into an immutable **version**, which is what the storefront renders. Domain:
`app/Domain/Content/Blocks`. Admin: `resources/js/admin/features/content/home` (Figma B · 26 Page
builder `241:44102`). Storefront: `toymartbd-app/storefront/components/content/**`.

## Data

- `cms_blocks` — the draft: `uuid` (identity across versions), `placement` (`home`), `type`,
  `label` (internal), `content` JSON, `position`, `is_enabled`, `show_en`, `show_bn`, `show_mobile`,
  `show_desktop`, `starts_at` / `ends_at` (UTC; entered in Asia/Dhaka).
- `cms_layout_versions` — append-only: `version` (per placement), `blocks` (ordered snapshot),
  `content_hash`, `restored_from_version`, `note`, `published_by`, `published_at`. The live page is
  the highest version. The model refuses update and delete.

## Block types and their `content` schema

Validated server-side by `BlockType::schema()` (`app/Domain/Content/Blocks/Schemas/*`), applied by the
block FormRequests under `content.*`; stored normalised (unknown keys dropped, empty strings → null,
rich text sanitised with the product-description allowlist). Copy is a pair `x` (English) / `x_bn`
(Bangla, optional — the storefront falls back to English).

| Type | Content |
|---|---|
| `hero` | `show_age_pills`, `slides[1..5]`: `headline`*, `overline`, `sub`, `cta_label`, `cta_href` (store path or https), `media_id` (library image; none = built-in art), `alt` |
| `category_tiles` | `title`*, `source` `top_level`\|`manual`, `category_ids` (manual), `limit` 2–12, `show_see_all` |
| `age_tiles` | `title`*, `show_see_all` — counts are live |
| `product_rail` | `title`*, `rule` `new`\|`best_sellers`\|`category`\|`brand`\|`manual`, `category_id`, `brand_id`, `product_ids` (≤ 24), `window_days` 7\|30\|90, `limit` 2–12, `min_items` ≤ limit, `hide_sold_out`, `show_see_all` |
| `flash_sale` | `title`*, `limit` 2–12 — a slot: the storefront reads the running sale from `GET /storefront/flash-sales` (tags `flash-sale`, `products`) and hides the block when none runs |
| `brand_strip` | `title`*, `brand_ids` (none = active brands with a logo), `limit` 3–24 |
| `usp_band` | `eyebrow`, `title`*, `items[2..4]`: `icon` (cod, return, authentic, support, delivery, gift, educational), `title`*, `detail` (`{phone}` = hotline) |
| `promo_split` | `headline`*, `overline`, `body`, `cta_label`, `cta_href`, `media_id`, `alt`, `image_side` left\|right |
| `testimonials` | `title`*, `limit` 1–12 — published testimonials, in their order |
| `blog_teaser` | `title`*, `sub`, `limit` 1–6 — latest live posts (StorefrontBlogPostCard) |
| `rich_text` | `html`*, `html_bn` |
| `faq` | `title`*, `items[1..20]`: `question`*, `answer`* (plain text; the storefront adds FAQPage JSON-LD) |

## Admin API (`/api/v1/admin`, content.view to read, content.manage to change)

| | |
|---|---|
| `GET /content/home` | `{ placement, blocks: CmsBlock[], live: CmsLayoutVersion\|null, has_unpublished_changes, block_types: [{type, defaults}], media: [{id, thumb_url, alt_text, alt_text_bn}] }` |
| `POST /content/home/blocks` | `{ type, position?, content?, label?, is_enabled?, show_en?, show_bn?, show_mobile?, show_desktop?, starts_at?, ends_at? }` → 201 `CmsBlock`. Without `content` the type's defaults are used. A time without an offset is Dhaka time |
| `PATCH /content/home/blocks/{id}` | any subset of the same; `content` replaces the block's content whole → `CmsBlock` |
| `DELETE /content/home/blocks/{id}` | 204 |
| `POST /content/home/blocks/{id}/duplicate` | 201 — a copy directly below |
| `PUT /content/home/order` | `{ ids }` — every draft block exactly once, else 422 |
| `POST /content/home/publish` | `{ note? }` → 201 `CmsLayoutVersion`; 409 `conflict` when the draft equals the live version |
| `GET /content/home/versions` | `{ data: CmsLayoutVersion[] }` (50 newest) |
| `POST /content/home/versions/{id}/revert` | `{ publish = true }` → 201 new live version (`restored_from_version` set); `publish: false` only replaces the draft (200 draft) |
| `POST /content/home/preview-token` | `{ locale }` → `{ token, expires_at, url }` — a 10-minute `App\Support\PreviewToken` of type `home_draft`; `url` is `{STOREFRONT_URL}/api/preview/home?token=…&locale=…` (null without STOREFRONT_URL) |

## Storefront API (`/api/v1/storefront`, storefront token)

- `GET /content/home?locale=en|bn` — the live version, resolved for that locale **now**: disabled,
  out-of-window and other-locale blocks removed; copy single-language; per-type data added (see
  `StorefrontHomeResource`). Guardrails (rule 25c): rails list only listable products, drop sold-out
  ones when `hide_sold_out`, and a rail below `min_items` is removed; any block whose data is empty
  is removed. `{version: null, blocks: []}` before the first publish.
- `GET /content/home/preview?locale=…[&at=ISO]` with `X-Preview-Token` — the draft, same shape;
  403 `forbidden` for a missing, forged, other-type or expired token. Never cached.

## Caching and revalidation

- Laravel caches the live snapshot until the next publish/revert. Seeding the default homepage publishes version 1 without queueing a revalidation.
- The storefront caches `GET /content/home` under `content:home` plus `products`, `categories`,
  `brands`, `content:testimonials`, `content:blog`.
- `content:home` is sent (queued, after commit) on every publish and revert
  (`CmsLayoutPublished` → `RevalidateCmsLayout`), and by `toymart:content:revalidate-schedule`
  (every minute) when a `starts_at`/`ends_at` of the live version has passed since the last run.

## Preview

`/api/preview/home` (Next.js route handler) checks the token's shape and expiry, asks the API to
verify it (the HMAC key never leaves Laravel), then enables draft mode and keeps the token in an
httpOnly cookie (`SameSite=None; Secure` in production, for the admin's cross-site iframe). The home
page, in draft mode only, renders the draft under a "Draft preview" bar. `/api/preview/home/exit`
leaves it.
