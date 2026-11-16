# Storefront Themes — spec

> **Not implemented. Design and docs only** (see [README](README.md) for the branch policy).
> Figma: page **Admin — Themes (proposal)** (`479:13783`), screens T·01–T·04.

## 1. Goal

Let the owner change the storefront's entire look from the admin, choosing from a library of built-in themes for different kinds of business, without touching any content. Think WordPress *Appearance › Themes*, restricted to themes we ship.

## 2. Principles

1. **Content is never theme-owned.** Products, stock, prices, orders, customers, CMS pages and blocks, menus, media, delivery, payments, settings and SEO are global and survive any number of switches.
2. **Switching writes one setting**: the active theme key. Nothing else is created, copied or deleted.
3. **Built-in only.** Themes ship with the codebase. There is no upload, marketplace or third-party code.
4. **No demo content.** A theme never imports pages, products or blocks.
5. **Hide, never delete.** A homepage block type that a theme cannot render is skipped at render time and flagged in the admin. It returns when a supporting theme is active.
6. **Business logic stays in Laravel** (CLAUDE.md rule 1). A theme is presentation only and never computes a price, discount or delivery charge.

## 3. Theme library (v1)

| Key | Name | Business type | Type pairing |
|---|---|---|---|
| `babur-gallery` | Babur Gallery | Kids & baby lifestyle | Fraunces + Instrument Sans |
| `toymart-classic` | Toy Mart Classic | Kids & toys (today's storefront) | Fredoka + Hanken Grotesk |
| `bazaar-fresh` | Bazaar Fresh | Grocery & daily needs | Gabarito + Figtree |
| `noor` | Noor | Fashion & modest wear | DM Serif Display + Manrope |
| `volt` | Volt | Electronics & gadgets (dark) | Space Grotesk + Onest |
| `glow` | Glow | Beauty & cosmetics | Playfair Display + Urbanist |
| `shefa` | Shefa | Pharmacy & health | Manrope |
| `pathshala` | Pathshala | Books & stationery | Literata + Instrument Sans |
| `nest` | Nest | Home & furniture | Young Serif + Figtree |
| `mithai` | Mithai | Sweets, bakery & food | Caprasimo + Nunito |

Each theme's colours are modelled in Figma as one mode of the `Storefront Themes` collection, with these variables: `theme/bg`, `surface`, `primary`, `onPrimary`, `accent`, `soft`, `text`, `muted` and `border`. The code token set should mirror this: one token file per theme with the same semantic names. The rule against hardcoded hex values (CLAUDE.md rule 26) applies inside every theme.

Every theme must also satisfy:
- **Bangla:** `/bn/` routes, a Bangla font pairing and the 1.15× line-height rule (rules 35–38).
- **Western numerals** everywhere.
- **Accessibility and performance budgets:** `UI-UX-DESIGN-SPEC.md` §7–8.

## 4. Admin screens

**Sidebar.** A new **Themes** item in the *Store* group, below *Online store*, with two sub-items: *Theme library* and *Customize*. It is visible only with `themes.manage`.

### T·01 Theme library
- **Header:** "Themes", the explanation that only the design changes, and the actions *View store* and *Customize active theme*.
- **Active theme card:**
  - preview, name, version and business type
  - supported features
  - activated date and the staff member who activated it
  - section count with the number hidden
  - actions: *Customize*, *Edit homepage*, *Download backup* (the customization JSON)
- **Safety note:** switching never deletes anything.
- **Filter tabs** by business type with counts, plus search.
- **Theme cards:** preview, name, chips (*Previously active*, *New*, *Popular*), palette dots, font pairing, *Preview* and *Activate*.

### T·02 Theme preview
A full-screen view with a details panel and a browser-framed preview, switchable between desktop and mobile.

The panel shows:
- the theme's look (palette, fonts, corners) and what kind of store it suits
- **"Your homepage in this theme"**: each current homepage block, marked supported or *hidden, not deleted*, with a total such as "11 of 12 sections supported"
- *Activate* and *Customize before activating*
- *Previous / Next* to step through themes

The preview renders the store's **real** catalogue, pages and menus.

### T·03 Activate confirmation
A dialog with:
- what changes (look, layout, fonts, how sections are styled)
- what stays (everything else)
- a warning for any unsupported block
- options: **Keep my current theme's customizations** (on by default) and **Schedule instead of activating now** (date and time)
- *Cancel*, *Preview first*, *Activate theme*

### T·04 Customize
A full-screen editor that reuses the Page builder shell. A settings panel sits beside a live preview, where clicking an element in the preview jumps to its setting.

| Group | Settings |
|---|---|
| Logo & identity | Logo, favicon (default: the logo mark) |
| Colours | Presets (*From logo*, *Theme default*, *Festive*, *High contrast*), editable primary, accent, background and text. A WCAG AA contrast check blocks publishing a failing pair. |
| Typography | Heading font, body font, Bangla font (auto-paired), text-size scale |
| Layout & style | Header (Classic / Centred / Minimal), image frames, buttons, product card |
| Collapsed links | Homepage sections (opens the Page builder), header & footer, social & contact |

The draft is saved automatically. *Publish changes* goes live and *Discard* reverts. There is an EN/বাংলা preview toggle.

## 5. Behaviour

| Event | Result |
|---|---|
| Preview | A signed, short-lived, **uncached** preview URL renders the chosen theme with real data. It never affects the live storefront or its cache. |
| Activate | `storefront.theme.active` is set to the theme key. The storefront cache tag is revalidated and the change is audited. If *keep customizations* is ticked, the outgoing theme's published customization stays stored, and it always does. |
| Schedule | A queued job activates the theme at the chosen Dhaka time. The pending schedule shows on T·01 and can be cancelled. |
| Customize | Each theme has its own draft and published customization with versions, like the Page builder's layouts. Publishing revalidates the storefront. |
| Unsupported block | Skipped by the storefront renderer and listed on T·02/T·03. It is never removed from `cms_blocks`. |
| Switch back | The theme's last published customization is restored as it was. |

## 6. Data (proposal)

Nothing below exists yet. Follow `DATABASE-DESIGN.md` conventions and use one migration per concern.

- **Theme registry:** in code, not the database. Each theme is a PHP class or config (`key`, `name`, `business_type`, `version`, `supported_block_types[]`, token defaults, font pairing, layout options). Built-in only, so the registry can't be extended from the admin.
- **Active theme:** a setting `storefront.theme.active` in the existing `settings` table, group `storefront`.
- **`theme_customizations`:** `id`, `theme_key`, `status` (draft | published), `payload` JSON (logo media id, colours, fonts, layout options), `version`, `published_at`, `published_by`, timestamps. Unique on (`theme_key`, `status`) for the current row; earlier versions are kept for rollback.
- **`theme_activations`** (schedule and history): `id`, `theme_key`, `scheduled_for` (nullable), `activated_at` (nullable), `cancelled_at` (nullable), `actor_id`.

## 7. API (proposal)

| Route | Notes |
|---|---|
| `GET /api/v1/admin/themes` | Registry, plus the active theme, a per-theme `supported` / `hidden` block summary for the current homepage, and the pending schedule |
| `GET /api/v1/admin/themes/{key}` | Detail for T·02 |
| `POST /api/v1/admin/themes/{key}/preview-token` | Signed preview URL |
| `POST /api/v1/admin/themes/{key}/activate` | Body `{ keep_customizations: bool, scheduled_for?: datetime }`. Uses a FormRequest. |
| `DELETE /api/v1/admin/themes/schedule/{id}` | Cancel a schedule |
| `GET` / `PUT /api/v1/admin/themes/{key}/customization` | Draft read and write |
| `POST /api/v1/admin/themes/{key}/customization/publish` | Publish the draft |
| `GET /api/v1/storefront/store-info` | **Additive:** `theme: { key, tokens, fonts, layout }`. Allowed under the additive-only rule (13). |

All admin write routes need FormRequests, API Resources and RFC 9457 errors (rules 14, 15, 18).

## 8. Permissions and audit

- New permission **`themes.manage`**, granted by default to `super_admin` and `manager`. Roles are editable (rule 20b), so the owner can grant it to others.
- *View store* and the read-only library also accept `content.view`. Activate, schedule, customize and publish need `themes.manage`.
- Every activation, schedule, cancellation and publish is written to the activity log.
- Role tests must assert 403 for roles without the permission (rule 21).

## 9. Storefront impact

The Next.js storefront resolves the active theme from `store-info` at request time, cached by tag and revalidated on activate or publish. It then loads that theme's tokens, fonts and component variants. Block rendering checks `supported_block_types`.

Rules that hold under every theme:
- checkout shows no recommendations (25b)
- prices come only from the API (rule 1)
- tracking needs order number plus phone (rule 22)

## 10. Acceptance criteria (for when implementation is approved)

1. Switching between any two themes leaves the counts of products, orders, customers, `cms_blocks`, menus and media unchanged.
2. A block unsupported by the active theme is absent from the rendered homepage and present again after switching back.
3. The preview URL never changes the live storefront and is never served from the cache.
4. A role without `themes.manage` gets 403 on every write route, with tests per rule 21.
5. Every theme passes the existing storefront performance and accessibility CI gates, in English and in `/bn/`.
6. Every activation, schedule and publish appears in the activity log with its actor.

## 11. Open items

- Confirm the decision 3 interpretation: managers may switch themes.
- Business values in the theme previews (delivery threshold, hotline, prices) are placeholders, as in `UI-UX-DESIGN-SPEC.md` §10.
- The Figma theme previews for the eight new themes are homepage-level only. Full page sets (listing, product, cart, checkout) exist only for Babur Gallery.
