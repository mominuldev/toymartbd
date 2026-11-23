# Smart Junior — Storefront Rebrand Design

**Status:** Design complete in Figma; not yet built · **Recorded:** 2026-11-23
**Owner decision (2026-11-23):** the storefront is being **rebranded to Smart Junior** using this design. It replaces the Toy Mart storefront look; it is not a second store or a theme.

**Figma file:** [Smart Junior](https://www.figma.com/design/2b6PVOOapPbcaKYIdHWZup/Smart-Junior?node-id=14-292) · file key `2b6PVOOapPbcaKYIdHWZup` (Decent Themes team)

This is a **separate file** from the Toy Mart design file `PWZp5VzEgEEf5N2Y38lGIh`. For the storefront, this file is now the source of truth. The admin keeps its existing design (Theme B, in the Toy Mart file) unless the owner says otherwise.

---

## 1. Identity

| | |
|---|---|
| Name | **Smart Junior** |
| Tagline | Play • Learn • Grow |
| Range | Kids' products, educational toys, soft toys, essentials |
| Tone | Pastel, premium, not childish |
| UX reference | Khel Napati (khelnapati.com): we took its patterns, not its look |
| Logo | A smiling squircle with a two-leaf sprout. Variants: Full Light, Full Dark, Mark, Stacked (component `5:51`) |
| Icons | Phosphor (bold / fill), 24 px (`4:2`). This matches the existing rule: Phosphor, never Lucide |
| Photography | **Product photos in the file are AI placeholders.** The Product Card tints them with a pastel MULTIPLY fill. Real photos replace them at build time |

### 1.1 Typography

| Role | Family | Styles (Figma `SJ/...`) |
|---|---|---|
| Display, headings, prices | **Bricolage Grotesque** | `Display/L` 52/800 · `Heading/H1` 40/700 · `H2` 32/700 · `H3` 24/700 · `H4` 20/600 · `Price/M` 18/700 |
| UI and body | **Plus Jakarta Sans** | `Body/L` 16 · `Body/M` 14 · `Body/S` 13 · `Label/L` 16/700 · `Label/M` 14/600 · `Label/S` 12/600 · `Title/Card` 15/600 · `Caption` 12/500 · `Overline` 12/800, +8 tracking |
| Bangla | **Anek Bangla** | The `৳` sign is its own Anek Bangla text node |

The CLAUDE.md rules still apply in code. Bangla gets about 1.15× the line-height through `.lang-bn` (rule 38). Numerals are Western in both locales (rule 36).

### 1.2 Colour (light, from the Figma variables)

| Token | Hex | Use |
|---|---|---|
| `action/primary` | `#12806f` | **Lagoon teal**: the action colour |
| `action/primary-soft` | `#e7f6f3` | Soft button and selected fills |
| `action/accent` · `brand/play` | `#ff7a59` | Coral accent |
| `brand/learn` | `#4a9bf0` | Sky |
| `text/primary` · `bg/inverse` | `#1e2140` | Navy ink |
| `text/secondary` | `#3a3f63` | |
| `text/muted` · `icon/muted` | `#676c8e` | |
| `text/brand` | `#0c6457` | |
| `text/success` | `#1e7a48` | |
| `text/on-butter` | `#7a5600` | |
| `bg/canvas` | `#fffcf7` | Warm off-white page |
| `bg/surface` | `#ffffff` | Cards, panels |
| `border/default` | `#d4d6e3` | |
| `border/subtle` | `#ecebf2` | |
| `icon/star` | `#ffc94a` | Ratings |

**Pastels.** Each pastel has three tones: a base, a `-strong` version and an `-ink` version for text on it.

| Pastel | base | strong | ink |
|---|---|---|---|
| coral | `#fff1ec` | `#ffddd0` | `#b63d20` |
| sky | `#ebf5ff` | `#d4e8ff` | `#1f5fa8` |
| butter | `#fff9e5` | `#fff0bf` | — (`text/on-butter`) |
| lilac | `#f4f0ff` | `#e6deff` | `#5b45c2` |
| blush | `#fff1f5` | `#ffdde7` | `#a8355a` |
| mint | `#ecf8f0` | `#cff0db` | `#1e7a48` |
| lagoon | `#e7f6f3` | `#c9ede5` | `#0c6457` |

The primitive ramps are `coral`, `sky`, `butter`, `mint`, `lagoon` and `ink`, at 200–700. The full set is in the variable collections below. This table is a reading aid, not the export.

### 1.3 Space, radius, elevation

- **Space** (`SJ · Space`): 4 · 6 · 8 · 10 · 12 · 16 · 20 · 24 · 32 …
- **Radius** (`SJ · Radius`): `xs` 6 · `sm` 10 · `md` 14 · `lg` 20 · `xl` 28 · `2xl` 36 · `full` 999
- **Effects:** `SJ/Elevation/e1…`, `SJ/Focus`, `SJ/Glow`
- **Desktop frame:** 1440 wide, content 1200, 120 px side margin

### 1.4 Variable collections

`SJ · Primitives`, `SJ · Color`, `SJ · Space`, `SJ · Radius`. Text styles are `SJ/...`. Effect styles are `SJ/Elevation|Focus|Glow`.

---

## 2. File map

| Page | id | Contents |
|---|---|---|
| Cover | `2:2` | |
| Smart Junior | `0:1` | All screens, one row per flow |
| Foundations | `2:3` | Colour, type, space, radius, effects |
| Components | `2:4` | The component library (§4) |

---

## 3. Screens (page `0:1`)

| Flow | Desktop 1440 | Tablet 834 | Mobile 390 | Notes |
|---|---|---|---|---|
| 01 · Home | `14:2` | `19:2038` | `17:1314` | |
| 02 · Category listing | `20:2659` | — | `21:3347` | Mobile filter sheet `21:3861` |
| 03 · Product detail | `22:3828` | — | `24:4382` | Mobile has a sticky buy bar |
| 04 · Cart | `25:4628` | — | `25:5223` | One cross-sell rail, no upsell (rule 25b) |
| 05 · Checkout | `26:5238` | — | `27:5295` | Focused, no recommendations (rule 25b) |
| 06 · Order confirmed | `27:5418` | — | `27:5692` | |

### 3.1 Home sections (desktop `14:2`, top to bottom)

| Section | id | Mobile | Tablet |
|---|---|---|---|
| Header | `14:3` | `17:1315` | `19:2039` |
| Hero | `14:159` | `17:1377` | `19:2076` |
| Trust strip | `14:245` | `17:1418` (scroller) | `19:2113` |
| **Shop by category** (the linked node) | `14:292` | `17:1446` | `19:2139` |
| Flash deals | `15:289` | `17:1529` | `19:2219` |
| Shop by age | `15:531` | `17:1751` | `19:2366` |
| Bestsellers | `15:594` | `18:1695` | `19:2414` |
| Promo banners | `16:901` | `18:1905` (gift banner) | — |
| New arrivals | `16:932` | — | — |
| **Gifts & combos** (§11) | `57:18557` | `57:18747` (after the gift banner) | — |
| Why Smart Junior | `16:1124` | `18:1918` | `19:2717` |
| **Video reel** (replaced Reviews, §10) | `54:5625` | `54:5747` | — |
| Play & learn journal | `16:1255` | — | — |
| Help CTA | `16:1306` | `18:1978` | `19:2737` |
| Footer | `16:1324` | `18:1993` | `19:2747` |
| Bottom tab bar | — | `18:2086` | — |

Home is section-based, so each section maps to a CMS block in the homepage builder (rule 34a). The sections that have no block today are gaps to fill.

### 3.2 Other screens, by section

- **Category, desktop:** Header · Category hero `20:2776` · Body (filters and grid) `20:2859` · About category `20:3449` · Footer
- **Category, mobile:** Header · Category hero `21:3385` · Filter bar `21:3404` · Grid `21:3415` · Footer · Tab bar. Filter sheet: Scrim `21:3862` + Sheet `21:3863`
- **Product, desktop:** Breadcrumb `22:3945` · Product main `22:3956` · Details `23:4008` · Reviews `23:4090` · Frequently bought together `23:4210` · You may also like `23:4279`
- **Product, mobile:** Top bar · Gallery `24:4402` · Thumbnails · Info `24:4424` · Accordions `24:4494` · You may also like `24:4517` · Sticky buy bar `24:4718`
- **Cart:** Title row · Body `25:4764` · Cross-sell rail `25:4945`. Mobile has a sticky checkout bar `25:5491`
- **Checkout:** Checkout header `26:5239` · Body `26:5261`. Mobile: Order toggle `27:5306` · Form `27:5313` · Sticky place order `27:5407`
- **Order confirmed:** Content `27:5535` (desktop), `27:5730` (mobile)

---

## 4. Components (page `2:4`)

| Component | Set id | Variants |
|---|---|---|
| Icons | `4:2` | 55 Phosphor icons, `Icon/<Name>` |
| Logo | `5:51` | Full Light · Full Dark · Mark · Stacked |
| Button | `5:124` | Primary · Secondary · Accent · Soft · Outline · Ghost × L 52 / M 44 / S 36. Corners: `radius/md` 14 for L and M, `radius/sm` 10 for S. These were pills (`radius/full`) until §12 |
| Icon Button | `5:161` | Surface · Soft · Primary · Ghost × 48 / 40 / 32. Rounded squares: `radius/md` for L and M, `radius/sm` for S. These were circles until §12 |
| Badge | `5:176` | Sale · New · Bestseller · Age · Stock · Gift · Low |
| Price | `7:59` | L / M / S × Default · Sale · Was |
| Eyebrow | `7:80` | Coral · Sky · Mint · Lilac · Butter |
| Rating | `7:109` | M · S |
| Search | `7:120` | Desktop 560 · Mobile 358 |
| Qty Stepper | `7:137` | M · S |
| Chip | `7:146` | Default · Selected |
| Selection Control | `7:162` | Checkbox / Radio × Off / On |
| Input | `7:178` | Default · Focus · Error |
| Trust Item | `7:214` | Sky · Mint · Butter · Coral · Lilac |
| Product Card | `9:146` | Desktop 272 × 450 · Mobile 171 × 321 |
| Category Tile | `9:159` | Desktop 136 × 187 · Mobile 80 × 100. Circle tile with 3D art (§13) |
| Age Card | `9:176` | Desktop · Mobile |
| Section Header | `9:213` | Desktop 1200 · Mobile 358 |
| Header | `10:289` | Desktop 1440 × 205 · Mobile 390 × 159 |
| Bottom Tab Bar | `10:290` | 390 × 108 |
| Footer | `11:376` | Desktop · Mobile |

---

## 5. What the rebrand touches (when building)

The design is done. Nothing below has been built yet.

1. **Tokens.** Export the `SJ · *` collections into `packages/tokens` and run `npm run tokens:build`. Then run `npm run sync:storefront` and commit the result in `../toymartbd-storefront` (rule 25). Components use semantic tokens only (rule 26), so most of the rebrand should be a token swap. Where it isn't, a component is binding a primitive, and that is a defect.
2. **Fonts.** Bricolage Grotesque and Plus Jakarta Sans replace the current storefront Latin faces. Anek Bangla stays.
3. **Storefront components.** Rebuild Header, Footer, Product Card, Category Tile and Age Card, plus the home sections, to match §3–4. The work is in the storefront repo.
4. **CMS blocks.** Any home section without a block (for example Shop by age, Play & learn journal, Why Smart Junior) needs a block type in Laravel (rule 34a).
5. **Brand strings.** Store name, logo, favicon, OG images, email/invoice templates in `resources/views/`, and the SEO titles all need to change. Some of these may be settings (Branding) rather than code.
6. **Rules that still hold:** no recommendations on checkout and only one cross-sell rail in the cart (25b). Both are already reflected in the design.

## 6. Open questions for the owner

- **Domain and business name.** Does the domain change from toymartbd, and does the legal/trading name on invoices change too?
- **Admin.** Does the admin keep Theme B and the Toy Mart name, or does it take the Smart Junior logo and name?
- **Dark mode.** The Smart Junior file defines light values only. Does the storefront ship with a dark mode?
- **Babur Gallery / Themes proposal** (`docs/global-site/`, the `global-site` branch). Does it still stand, with Smart Junior as the default theme?
- **Real assets.** We need logo source files (SVG) and real product photography in place of the AI placeholders.

---

## 7. Variation 2 — "Vivid · বাংলা" (2026-11-23)

A second look for the same storefront. The colour change is made entirely in the tokens. Type, pattern and footer were changed only for this variation. Owner requests: a Coolors palette, a Bangla-first copy with **product names kept in English**, rounded kid-friendly type, the pattern only in specific sections, a fancier footer, and buttons in `#F15BB5`.

**Figma page:** `Smart Junior — Vivid · বাংলা` (`34:5658`). It holds all 14 screens, duplicated from page `0:1`. Each screen frame sets `SJ · Color` to the **Vivid** mode explicitly. The original page is untouched and still renders in **Light**.

### 7.1 Colour: the Vivid mode on `SJ · Color` (mode id `34:0`)

Source palette: [coolors 9b5de5-f15bb5-fee440-00bbf9-00f5d4](https://coolors.co/palette/9b5de5-f15bb5-fee440-00bbf9-00f5d4). Five new primitive ramps sit in `SJ · Primitives`: 50 / 100 / 300 are tints, 500 is the palette colour, and 600 / 700 are darkened for contrast.

| Ramp | 500 | 600 (white text ≥ 4.6:1) | 700 (on its 100 tint ≥ 6:1) | Replaces in Vivid |
|---|---|---|---|---|
| `grape` | `#9b5de5` | `#9350e3` | `#6d20c9` | lagoon, lilac |
| `bubblegum` | `#f15bb5` | `#dd138c` | `#a30e67` | coral, blush |
| `lemon` | `#fee440` | `#867301` | `#655701` | butter |
| `splash` | `#00bbf9` | `#007ea7` | `#005d7c` | sky |
| `aqua` | `#00f5d4` | `#008271` | `#006456` | mint |

Neutrals (ink, cream, white) are the same in both modes.

**Button colour (owner, 2026-11-23):** `action/primary` = `bubblegum/500` **#F15BB5**. White text on #F15BB5 is only **3.0:1**, which fails WCAG AA, so primary labels and icons now use the new **`text/on-primary` / `icon/on-primary`** tokens. These are white in Light and `ink/900` in Vivid (navy on #F15BB5 = 5.1:1). Every Button, Icon Button, checkbox and badge on a primary fill was rebound to them. Light looks the same as before. Other Vivid action colours:
- `action/primary-hover` = `bubblegum/300`
- `action/primary-soft` = `bubblegum/50`
- `action/accent` = `aqua/500`, so accent buttons stay distinct from primary.

**Effects follow the mode too.** `SJ/Focus/ring`, `SJ/Glow/lagoon` and `SJ/Glow/coral` were raw hex. They are now bound to `effect/focus-ring`, `effect/glow-primary` and `effect/glow-accent`. Before this change, every fill and stroke on the screens was already token-bound (4,699 bindings, zero raw).

### 7.2 Type (Vivid only): `SJ Vivid/*` text styles

| Role | Font |
|---|---|
| Bangla titles: Display, H1–H3 | **Hind Siliguri** Bold |
| Bangla text, buttons, labels, prices, H4 | **Anek Bangla** (weights as in `SJ/*`) |
| Latin words, product names, digits | **Fredoka** (rounded), set per character range inside mixed strings |

- Bangla line-height is about 1.15× the Latin value (CLAUDE.md rule 38).
- Numerals stay Western (rule 36).
- Tracking is 0 and Overline is not uppercased in Bangla.

Rejected along the way (owner): Baloo Da 2, Nunito, Noto Sans Bengali, Mina, Atma, and Galada for titles. Hind Siliguri was first rejected for body text, then chosen for titles.

### 7.3 Copy

All UI copy is in Bangla. These stay in English:
- product names, the brand name and the logo lockup
- payment brands (bKash, Nagad, Visa, Mastercard, SSLCommerz), WhatsApp, STEM
- phone numbers, the email address, SKUs and order numbers

The translations were done in the design. They are not yet strings in the i18n layer.

### 7.4 Pattern: component `Pattern / Confetti` (`39:252`, Components page)

A 480×480 seamless tile of stars, hearts, rings, plus signs, squiggles, triangles and dots. Its colours are the brand tokens (`brand/play|learn|sun|grow|lagoon`) at 9–28% opacity per shape (halved on owner request), so it follows the mode: pastel in Light, vivid in Vivid.

Owner: **sections only, not the whole page.** It appears in:
- Home: Flash deals, the "Why Smart Junior" band, the Help CTA card, and the gift banner (mobile)
- the Category hero
- the Order-confirmed content
- the footer

Cart and checkout carry no pattern, following `03-storefront-spec.md` §0 rule 9: nothing playful below a decision.

### 7.5 Footer: component set `Footer — Vivid · বাংলা` (`41:9166`: Desktop `41:8217`, Mobile `41:8832`)

- scalloped wave top
- brand-colour blobs and the confetti layer, also inside the club card
- column headings in lemon / aqua / pink / blue
- colourful social buttons
- the logo mark peeking over the wave
- a five-colour rainbow stripe at the bottom

The Bangla copy is baked into this set, so it is a Vivid-only component. All 9 footer instances on the Vivid page use it. The tablet uses the Mobile variant stretched to 834.

### 7.6 Not done / open

- **Hero and product photos** are still the AI placeholders from the Light design.
- **Code.** Nothing is built yet. Building this variation means adding the Vivid mode to `packages/tokens` as a second storefront theme. It also needs the three web fonts (Hind Siliguri, Anek Bangla, Fredoka; all on Google Fonts, OFL), and the Bangla strings added to the storefront's i18n files.
- **Light-mode buttons.** These still use white labels on lagoon (4.6:1), which is unchanged.

## 8. Contrast audit — both storefront pages (2026-11-23)

The audit was scripted in Figma and covered every visible text segment and icon:
- Light page `0:1`: 2,058 text segments and 1,021 icons
- Vivid page `34:5658`: 2,579 text segments and 1,021 icons

Each colour was resolved in its screen's mode and composited against the real background stack (parent fills, sibling shapes underneath, opacity). It was then checked against WCAG 2.2 AA: 4.5:1 for normal text, 3:1 for large text (≥ 24 px, or ≥ 18.66 px bold), and 3:1 for icons and control boundaries (1.4.11).

**Result after fixes: 0 failures on both pages.**

| Found | Was | Fix |
|---|---|---|
| Rating stars (yellow), 380+ instances | 1.3 Vivid / 1.5 Light | New `icon/star-outline` stroke on every star in `Rating` (`butter/700` Light, `lemon/700` Vivid). The fill stays yellow; the outline is ≥ 6:1 |
| Text-input, search, select, coupon and note borders (`border/default`) | 1.45 | New primitive `ink/400` = `#8388a6` → new `border/input` (3.48 on white, 3.40 on canvas, 3.23 on cream/100). Applied to `Input`, `Search` and the local fields on both pages |
| Checkbox and radio "off" borders (`border/strong`) | 2.22 | `border/input` |
| Focus ring (`effect/focus-ring`) | 1.75 Light / 1.86 Vivid | Light `lagoon/700` (7.1), Vivid `bubblegum/700` (7.5) |
| Order-confirmed ✓ (`brand/grow` on mint) | 1.94 / 1.26 | `pastel/mint-ink` |
| "Sold out" label (`text/disabled`) | 2.22 | `text/muted` |
| Inactive checkout step numbers (`text/muted` on `bg/muted`) | 4.31 | `text/secondary` |

**Exempt by WCAG and left as they are:**
- the coloured "•" in the logo tagline (logotype)
- the "|" dividers and review quote marks (decorative)
- the disabled "150 pcs" option (inactive control)
- buttons, chips and option cards: they are identified by their labels, which pass, so their borders don't need 3:1

**Rule going forward:**
- New form controls use `border/input`, never `border/default`.
- Text on a coloured fill is checked in **both** modes. `brand/*` tokens are fills and accents, never body text on white.

## 9. Vivid hero and add-to-cart colours (2026-11-23)

- **Home hero (Vivid, all three sizes).** The main card moves to a new token, **`bg/brand-strong`** (Light `lagoon/700`, Vivid `grape/700`). On it:
  - headline and body in `text/inverse`
  - the word "খেলনা" accented in `brand/sun`
  - a lemon eyebrow chip
  - confetti plus soft `brand/sun` and `brand/grow` blobs

  The flash-sale card is now `pastel/coral-strong` and the STEM card `pastel/mint-strong` (was baby blue), both with white eyebrow chips. The hero now reads as one grape / pink / aqua palette. Every hero text passes AA.
- **Add to cart.** There is a new **`action/cart`** token (Light `butter/500`, Vivid `lemon/300` #FEF096; Vivid was `aqua/500` until §12), with labels and icons in `text/primary` / `icon/default` (11.1:1). It is used on all 67 add-to-cart controls on the Vivid page: the product-card cart buttons, the product-page button, the "add all 3" bundle button and the mobile cross-sell "যোগ করুন". "Buy now" stays `action/primary` pink, so the two actions never look alike.

## 10. Home: the video reel replaces the reviews (2026-11-23)

**Owner request:** replace the home page's testimonial section ("Parents are talking", three review cards) with a video reel, on a section background. Product-page reviews (`23:4090`) are not affected.

**Where it is:**

| Page | Desktop | Mobile |
|---|---|---|
| Light `0:1` | `54:5625` | `54:5747` |
| Vivid · বাংলা `34:5658` | `55:12790` | `55:12912` |

The tablet home never had a reviews section, so it has no reel.

**Structure:**
- A transparent wrapper, `Video reel`, keeps the section rhythm: top padding 96 on desktop, 36 on mobile.
- Inside it, a full-width `Band` uses `pastel/lilac` as the section background, the same way Flash deals uses `pastel/coral`. Padding is 64/120/72 on desktop. On mobile it is 28/16, with the reel bleeding off the right edge like the Flash deals scroller.
- The section header reuses `Section Header` with View all and prev/next arrows. Copy:
  - eyebrow "Watch & play" / "দেখুন ও খেলুন"
  - heading "Toys in action" / "খেলার মুহূর্ত"
  - subheading
- `Reels`: five cards, which fill the row on desktop. On mobile they are 156 px wide and scroll sideways.
- Each `Reel` card is `bg/surface` with radius 24 (20 on mobile). Inside it:
  - A **9:16** `Video` thumbnail, which carries:
    - a duration chip (`bg/inverse` with ▶ and time)
    - a centred play button (`bg/surface` with `icon/brand`)
    - a "shop this" product chip (`bg/surface`, product name and arrow), which links to the product page
  - Below the thumbnail, a `Caption`: a two-line title (`Title/Card`) and a view count (`Caption`, `text/muted`). Caption heights are equalised across the row.
- **Vivid:** `SJ Vivid/*` styles. Product names, durations and counts are set in Fredoka per character range (§7.3).

**Contrast on lilac** (§8 method): the lowest value is `text/muted` at 4.56 (Light) and 4.64 (Vivid), which passes AA.

**Open:**
- Thumbnails reuse the AI placeholder photos from the journal and hero, so they don't match the captions yet.
- The view counts and durations are placeholder values.
- Video hosting is undecided: self-hosted, YouTube Shorts, or Facebook/Instagram Reels. It decides the player, the poster images and the privacy and performance cost. That makes it an owner question before code.
- The section needs a CMS block in the homepage builder (rule 34a). Each reel is a video, a poster, a caption and a linked product.
- Recommendation attribution (rule 25d) may apply if the product chip counts as a placement.

## 11. Home: Gifts & combo sets (2026-11-23)

**Owner request:** add a gift and combo-set product row, from a reference screenshot.

**Where it is:**
- **Desktop:** after New arrivals.
- **Mobile:** after the Gift banner.
- **Tablet:** not added.

| Page | Desktop | Mobile |
|---|---|---|
| Light `0:1` | `57:18557` | `57:18747` |
| Vivid · বাংলা `34:5658` | `57:12772` | `57:12962` |

**Structure:**
- The same wrapper-and-band pattern as the video reel (§10). The wrapper keeps the page's section top padding (80 on desktop, 36 on mobile).
- The `Band` uses **`pastel/blush-strong`** as the section background (Light #FFDDE7, Vivid #FCDEF0), with padding 56/120/64 on desktop and 28/16 on mobile. On mobile the cards bleed off the right edge. The owner rejected `pastel/butter`: in Vivid it is #FFFAD9, which reads as off-white.
- The header is `Section Header` with View all and prev/next arrows. Copy:
  - eyebrow "Gift ideas" / "গিফট আইডিয়া"
  - heading "Gifts & combo sets" / "উপহার ও কম্বো সেট"
  - subheading "জন্মদিন বা যেকোনো উপলক্ষে প্রিয় শিশুর জন্য সেরা উপহার"
- The cards are `Product Card` instances, with the sale badge and was-price on:
  - **desktop:** four across at 282, the same grid as every other product row
  - **mobile:** the Flash deals card scroller
- Every card has the cart button and Buy now ("এখনই কিনুন").

**Contrast on blush-strong:** `text/muted` is only 4.07–4.10 here, which fails AA. So the subheading uses `text/secondary` (8.1:1). Headings and View all pass easily.

**Rule:** muted text never sits on a `-strong` pastel. Every `-strong` pastel puts `text/muted` below 4.5:1, in the range 3.95–4.61.

**Open:**
- The reference shows **five** cards per row. We kept four so the cards match the 282 product grid. At five across (≈221 px), the cart and Buy now buttons don't fit side by side.
- The reference has a dotted background. It is not added, because the owner limits patterns to specific sections (§7.4). Adding one is a single layer if wanted.
- The combo names, prices and discounts are placeholders. The photos are the existing AI product placeholders, matched to the titles.
- **Code:** the section is a product rail driven by a "gift"/"combo" collection or tag. That needs a CMS block that takes a product source (rule 34a), and the server sets the prices (rule 1).

## 12. Buttons, cart colour and card shadows (2026-11-23)

Owner requests:
- **Rounded buttons, not pills or circles.** Every `Button` and `Icon Button` variant now binds its corners to `radius/md` (14) at L and M, and `radius/sm` (10) at S. Instances inherit this, so all screens on both pages follow. Chips, badges, the duration and product chips in the video reel, and the eyebrow keep `radius/full`.
- **Add-to-cart colour.** The Vivid value of `action/cart` changed from `aqua/500` to **`lemon/300` #FEF096**. That gives a pale yellow button with a navy icon, as in the owner's reference. It still reads clearly against the pink Buy now. Light is unchanged. On the Vivid page, 75 cart paints were refreshed to the new base colour (gotcha 1).
- **Product-card shadows were being cut off.** The rows, scrollers and sections that hold product cards clipped their content, so the `SJ/Elevation` shadow was cropped at the row edge. These were the Flash deals, Bestsellers, New arrivals, Gifts, category grid, You may also like and cross-sell containers, on both pages. Clipping is now off on every container between a card and its screen frame. The screen frame still clips, so a mobile scroller still runs off the edge. Layers inside component instances were left as they were.

**Rule:** a container that holds elevated cards never clips its content. Let the screen frame do the clipping.

## 13. Category tiles: 3D art instead of icons (2026-11-23)

The owner asked for 3D category art in place of the Phosphor icons and supplied a reference: a pastel circle holding a glossy, saturated 3D object that nearly fills it.

- **Tile shape.** The `Tile` frame in both `Category Tile` variants now binds its corners to `radius/full`, so it is a circle. The tint fill is unchanged.
- **Art.** Nine components, `Category 3D/<Name>` (Components page, frame `77:1051`): Educational `77:1052`, Soft Toys `77:1054`, Books `77:1056`, Montessori `77:1058`, Art & Craft `77:1060`, STEM `77:1062`, Baby Care `77:1064`, Birthday `77:1066`, Gifts `77:1068`. Each holds one AI render (Figma `generate_image`) on white. The `Art` layer uses a **MULTIPLY** blend, so the white drops out against the tile tint, the same trick the product card uses.
- **Size.** The `Icon` instance-swap property (`Icon#9:18`) now points at the 3D art: 112 px on desktop and 68 px on mobile. All 48 tile instances on both pages (Light `0:1` and Vivid `34:5658`) were set by label. "Birthday Gifts" and "Gifts" now have their own art (cake, and gift boxes); before, both used the cake icon.
- **Caveats.**
  - The renders are placeholders, like the product photos. Production needs real transparent PNG or WebP files, which the CMS or the storefront would serve.
  - MULTIPLY only works on a light tint, so a dark mode would need transparent cut-outs.
