# CMS blocks: the homepage and page builders (Phase 7; pages 2026-11-16)

The homepage — and, since 2026-11-16 (owner decision), **every static page** — is an ordered list of typed blocks ("sections"). Each one is a **layout** with its own key: `home` or `page:{id}` (`App\Domain\Content\Blocks\ValueObjects\LayoutKey`). Editors change a **draft**; **publish** freezes the
draft into an immutable **version**, which is what the storefront renders. Domain:
`app/Domain/Content/Blocks`. Admin: `resources/js/admin/features/content/home` (Figma B · 26 Page
builder `241:44102`). Storefront: `toymartbd-storefront/components/content/**`.

## Data

- `cms_blocks` — the draft: `uuid` (identity across versions), `placement` (the layout key: `home` or `page:{id}`), `type`,
  `label` (internal), `content` JSON, `position`, `is_enabled`, `show_en`, `show_bn`, `show_mobile`,
  `show_desktop`, `starts_at` / `ends_at` (UTC; entered in Asia/Dhaka).
- `cms_layout_versions` — append-only: `version` (per layout), `blocks` (ordered snapshot),
  `content_hash`, `restored_from_version`, `note`, `published_by`, `published_at`. The live page is
  the highest version. The model refuses update and delete.

## Block types and their `content` schema

Validated server-side by `BlockType::schema()` (`app/Domain/Content/Blocks/Schemas/*`), applied by the
block FormRequests under `content.*`; stored normalised (unknown keys dropped, empty strings → null,
rich text sanitised with the product-description allowlist). Copy is a pair `x` (English) / `x_bn`
(Bangla, optional — the storefront falls back to English).

| Type | Content |
|---|---|
| `hero` | `show_age_pills`, `slides[1..5]`: `headline`*, `accent` (words of the headline drawn in the sun colour), `overline`, `sub`, `cta_label`, `cta_href` (store path or https), `cta2_label`, `cta2_href` (the outline button), `media_id` (library image; none = built-in art), `alt`; `promos[0..2]` (the mini promo cards beside the slides, Smart Junior 34:5686): `title`*, `overline`, `cta_label`, `cta_href`, `media_id`, `alt`, `tone` pink\|green\|blue\|yellow\|purple\|orange; `proof` (null or the row under the first slide's copy): `rating` 0–5, `label`, `media_ids[0..3]` (avatars) — the storefront gets `promos[].image` and `proof.avatars[]`; figures are editor-entered, never defaulted |
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
| `faq` | `title`*, `icon` (optional, as feature_tiles — the group's glyph in the topic list), `items[1..20]`: `question`*, `answer`* (plain text; the storefront adds FAQPage JSON-LD) |
| `image` | `media_id` (library image; a block without one is removed on the storefront), `alt`, `caption`, `href` (store path or https), `width` content\|wide |
| `contact_info` | `title` (optional since 2026-11-16), `sub`, `hours` (Call tile caption), `whatsapp_note`, `email_note`, `address_label` (Visit tile value instead of the full address), `address_note`, `map_href`, `show_hotline`, `show_whatsapp`, `show_messenger`, `show_email`, `show_address` (default true) — the values come from Settings › Store › Business via `GET /storefront/store-info`, never from the block; an unset value renders as the marked sample, never as a link |
| `story_hero` | `overline`, `title`*, `lead`*, `body`, `media_ids[0..3]` (library images, the sticker collage; the storefront gets `images[]` with only those that still exist), `badge`, `badge_sub`, `tone` sunrise\|sky\|mint\|candy — placed first on a page built from sections it replaces the page header and its title is the page's H1 (About us 170:10502) |
| `feature_tiles` | `title`, `items[2..4]`: `icon`* (shield_check, check_circle, gift, map_pin, truck, money, chat, phone, star, heart, puzzle, books, clock, user), `title`*, `body` — tile colours follow the position (mint, sky, butter, lavender) |
| `numbered_cards` | `title`*, `items[1..6]`: `title`*, `body` — numbered 01, 02… by position |
| `cta_band` | `title`*, `body`, `primary_label`/`primary_href`/`primary_icon`, `secondary_label`/`secondary_href`/`secondary_icon` (a label needs a store path or https link; icons as feature_tiles), `tone` candy\|sunrise\|sky\|mint |
| `contact_hero` | `overline`, `title`*, `lead`*, `status` (pill with a green dot), `bubbles[0..4]`: `from`* customer\|store, `label`, `text`* — placed first on a page built from sections it replaces the page header and its title is the page's H1 (Contact us 165:9891) |
| `contact_form` | `title`*, `intro`, `privacy` — the "Send us a message" card; its fields are fixed and post to `POST /storefront/contact-messages` (→ admin Inbox, channel `web_form`). Beside it: `visit_title`, `visit_body`, `visit_map_href`, `hours[0..6]`: `label`*, `value`*; `links_title`, `links[0..6]`: `icon`* (as feature_tiles, plus `clock`), `label`*, `href`* — a side card without its title is not shown |
| `help_hero` | `overline`, `title`*, `lead`, `show_search` (default true; filters the page's FAQ sections in the browser), `search_placeholder` — placed first it replaces the page header and its title is the page's H1 (Common questions 171:10981) |
| `chat_band` | `title`*, `body`, `button_label` — "Still need help?"; the button links to the store's WhatsApp from `GET /storefront/store-info` and is left out while that is the sample |

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

### Page builders

`/content/pages/{page}/layout` mirrors every `/content/home` endpoint above for one static page:
`GET /`, `POST /blocks`, `PATCH|DELETE /blocks/{id}`, `POST /blocks/{id}/duplicate`, `PUT /order`,
`POST /publish`, `GET /versions`, `POST /versions/{id}/revert`, `POST /preview-token` (token type
`page_draft` whose id is the **page** id; `url` is `{STOREFRONT_URL}/api/preview/page?…`). Same
policies and role split. A block or version of another layout is a 404 on either builder. The
page's own `status` still decides whether its URL is live; the layout decides what it shows.
Pages that predate the builder were moved by `BuildPageLayoutFromContent` (migration
`2026_12_07_720000`): `content` → a Rich text section, `faq_items` → FAQ sections of 20 (answers as
plain text), published as version 1. `cms_pages.content*` / `faq_items` stay for now
(expand-and-contract) and are what the storefront renders for a page whose layout was never published.

## Storefront API (`/api/v1/storefront`, storefront token)

- `GET /content/home?locale=en|bn` — the live version, resolved for that locale **now**: disabled,
  out-of-window and other-locale blocks removed; copy single-language; per-type data added (see
  `StorefrontHomeResource`). Guardrails (rule 25c): rails list only listable products, drop sold-out
  ones when `hide_sold_out`, and a rail below `min_items` is removed; any block whose data is empty
  is removed. `{version: null, blocks: []}` before the first publish.
- `GET /content/home/preview?locale=…[&at=ISO]` with `X-Preview-Token` — the draft, same shape;
  403 `forbidden` for a missing, forged, other-type or expired token. Never cached.
- `GET /content/pages/{slug}?locale=en|bn` — a live page: `{ page: StorefrontPage, version,
  published_at, preview, blocks }`, `blocks` resolved exactly as the homepage's. 404 when the page
  is not live. `GET /content/pages/preview?locale=…` with a `page_draft` token — that page's draft,
  whatever its status.

## Caching and revalidation

- Laravel caches the live snapshot until the next publish/revert. Seeding the default homepage publishes version 1 without queueing a revalidation.
- The storefront caches `GET /content/home` under `content:home` plus `products`, `categories`,
  `brands`, `content:testimonials`, `content:blog`.
- A page layout is cached under `content:pages` + `content:page:{slug}` plus the same data tags;
  Laravel sends that pair on a page layout publish/revert and at its schedule boundaries
  (`LayoutCacheTags`).
- `content:home` is sent (queued, after commit) on every publish and revert
  (`CmsLayoutPublished` → `RevalidateCmsLayout`), and by `toymart:content:revalidate-scheduled`
  (every minute) when a `starts_at`/`ends_at` of the live version has passed since the last run.

## Preview

`/api/preview/home` (Next.js route handler) checks the token's shape and expiry, asks the API to
verify it (the HMAC key never leaves Laravel), then enables draft mode and keeps the token in an
httpOnly cookie (`SameSite=None; Secure` in production, for the admin's cross-site iframe). The home
page, in draft mode only, renders the draft under a "Draft preview" bar. `/api/preview/home/exit`
leaves it.
