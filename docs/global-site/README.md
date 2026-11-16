# global-site — storefront themes

**Status:** design and documentation only. **Nothing here is implemented.** Do not write code for it until the owner says so.

## Branch policy

- All work for this initiative lives on the **`global-site`** branch, cut from `main` on 2026-11-17 (`489f6ef`).
- **`global-site` is never merged into `main`.** No PR from `global-site` targets `main`, and no merge or rebase goes in that direction.
- `global-site` may pull fixes **from** `main` (merge `main` → `global-site`) to stay current.
- Docs for this initiative live in `docs/global-site/`. Keep them current in the same commit as any later design or code change.

## What it is

One Toy Mart installation (one database, one admin) whose storefront look is chosen in the admin, the way WordPress themes work:

- The owner opens **Admin › Store › Themes**, previews a theme and activates it.
- Products, orders, customers, pages, menus, media, delivery, payments, settings and SEO **stay exactly the same**. Only the storefront's look changes.
- It is **not** a multi-brand or multi-tenant system. There is no second store, no `storefront_id`, and no separate data.

## Owner decisions (2026-11-17)

| # | Question | Decision |
|---|---|---|
| 1 | Built-in themes only, or allow uploads? | **Built-in only.** No theme upload. |
| 2 | Should themes ship optional demo pages or content? | **No.** Content is shared across all themes. |
| 3 | Who may switch themes? | **Owner and managers.** This is a new `themes.manage` permission, granted by default to `super_admin` and `manager`. Interpretation of the owner's "yes"; confirm if owner-only was meant. |
| — | Same admin for the Babur Gallery site? | **Yes.** Babur Gallery is a *theme* of the same store, not a separate brand. |

## Documents

| Doc | Contents |
|---|---|
| [THEMES-SPEC.md](THEMES-SPEC.md) | Product and technical spec for the Themes feature: screens, behaviour, data, API, permissions, acceptance criteria |
| [BABUR-GALLERY-THEME.md](BABUR-GALLERY-THEME.md) | The Babur Gallery theme's design system and storefront pages |
| [GAP-ANALYSIS.md](GAP-ANALYSIS.md) | What the current API and admin already support for the new storefront designs, and what is missing |

## Figma

File `PWZp5VzEgEEf5N2Y38lGIh` (Toy-Mart).

| Page | What |
|---|---|
| **Babur Gallery** (`445:2`) | Babur Gallery theme: foundations, components, photography, homepage (desktop / tablet / mobile), site chrome, and the listing, product, cart, checkout, order-confirmed, track-order, sign-in and account pages |
| **Admin — Themes (proposal)** (`479:13783`) | Admin screens T·01–T·04 (library, preview, activate confirm, customize), 10 theme preview components, the `Storefront Themes` variable collection (one mode per theme), and a developer handoff note |

The original admin designs (page **07 Admin — Desktop**) are **not modified**. Admin changes for this initiative go on new pages only.
