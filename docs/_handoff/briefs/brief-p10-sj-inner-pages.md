# Brief — Smart Junior inner pages: design specs before Figma (5 parallel agents, 2026-11-25)

**Owner decision (2026-11-25): Figma first.** The storefront pages below still have the old Toy Mart layout, and the
Smart Junior file has no frames for them. They are designed in Figma, approved by the owner, then coded. Figma writes are
strictly sequential (CLAUDE.md Figma rule 4), so this batch parallelises the **specification** work: each agent writes a
build-ready spec for its pages. One builder then makes the frames from the specs, one page at a time.

## Rules every agent follows
- **No Figma writes.** Never call `use_figma` or any tool that changes the file. Reads are fine: `get_metadata`,
  `get_design_context` (load the `figma:figma-design-to-code` skill first), `get_screenshot`, `get_variable_defs`.
  Before any read, call `create_session` with file key `2b6PVOOapPbcaKYIdHWZup` and pass its id on every call.
- **No git. No code changes** in either repo. You write exactly one file: your spec (path below).
- Read first: `CLAUDE.md`, `docs/_design/07-smart-junior-storefront.md` (§1, §4 components, §7 Vivid type and copy, §12–16),
  and the storefront repo's current page code at `/Users/mominul/Sites/toymartbd-storefront` for your pages. The spec designs
  **what the page actually shows today** (its real API fields, states and behaviour). Never invent data the API does not send.
  Where the design would want something the API lacks, put it under "Not designed — needs API" rather than drawing it.
- Target: the **Vivid · বাংলা** look (page `34:5658`). Frames are Bangla-first copy (§7.3: product names, brands, phone numbers,
  order numbers stay English), Western numerals (rule 36). Give the English string beside each Bangla one so i18n can be
  written from the spec.
- Reuse what exists: components from page `2:4` (by set id and variant), the Vivid frames' header, footer, mobile top bar and
  bottom tab bar, section header, card language (radius, elevation, pastel tones, confetti pattern only where the Vivid
  frames use it). Bind every colour, space and radius to an `SJ · *` variable or the `SJ Vivid/*` text styles. Name the
  token, never a hex. If a token is missing, say so (that is a defect to fix, not to work around).
- Rules that shape design: order tracking needs order number **plus** phone digits (rule 22); no recommendations on checkout
  (rule 25b); no business values the owner has not confirmed (hotline, hours, address, free-delivery threshold: mark them
  `placeholder`); accessibility: 44 px targets on mobile, visible focus, AA contrast (the §8 audit and §15 accepted gap),
  form errors in words not colour alone.

## Spec format (each page)
1. **Route(s) and purpose**, one line each; which current component files render it.
2. **Frames to build**: desktop 1440 and mobile 390 (tablet only if the page's layout changes there), with suggested frame
   names `NN · Page — Desktop` / `— Mobile` and where to place them on page `34:5658` (a free row; never overlap, rule 3).
3. **Sections top to bottom**: for each, layout (auto-layout direction, gap and padding tokens, widths), the components and
   variants used, every text with its `SJ Vivid/*` style, and the data field it shows.
4. **States**: empty, loading/skeleton if the page has one, error, signed-out, no-JS behaviour where it differs, and the
   Bangla long-text case. Say which states need their own frame (keep it to the ones that change layout).
5. **Copy table**: key → Bangla → English, for every string on the page.
6. **Deviations / open questions** for the owner.

Keep specs tight: a builder should be able to make the frames without opening the code. Target 150–350 lines per spec.

## Agents
| # | Pages | Storefront routes | Spec file |
|---|---|---|---|
| 1 | Sign-in and account set-up | `account/login`, `account/register`, `account/verify`, `account/set-password`, `account/reset-password` | `docs/_design/08-sj-inner/01-account-auth.md` |
| 2 | Account area | `account` (dashboard), `account/orders`, `account/orders/[number]`, `account/profile` (incl. kids/birthday consent), `account/addresses`, `account/wishlist` | `docs/_design/08-sj-inner/02-account-area.md` |
| 3 | Order status and system pages | `track`, `orders/[number]/payment`, `maintenance`, not-found (`global-not-found`, `[locale]` not-found), error (`global-error`) | `docs/_design/08-sj-inner/03-track-and-system.md` |
| 4 | Blog and guides | `blog`, `blog/[slug]`, `blog/category/[slug]`, `blog/tag/[slug]`, `blog/author/[slug]`, `guides`, `guides/[slug]` | `docs/_design/08-sj-inner/04-blog-and-guides.md` |
| 5 | Browse and info pages | `search` (incl. no results), `brands`, `brands/[slug]` header, `categories` (all categories), `about`, `contact-message` (contact), CMS static pages (`[slug]`, built from blocks, rule 34a), `delivery-charge` | `docs/_design/08-sj-inner/05-browse-and-info.md` |

Listing grids on search, brand and flash-sale already use the restyled ListingView (§16.3): spec only what differs (the page
header, the empty state), not the grid again.

## After the batch (lead)
Review the five specs for consistency (shared patterns once: page header, form card, empty state, breadcrumb), write
`docs/_design/08-sj-inner/00-shared.md`, then build the frames sequentially, screenshot each section, and hand the owner a
review list. Code starts only after the owner approves.
