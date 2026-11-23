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
| Why Smart Junior | `16:1124` | `18:1918` | `19:2717` |
| Reviews | `16:1147` | `18:1939` | — |
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
| Button | `5:124` | Primary · Secondary · Accent · Soft · Outline · Ghost × L 52 / M 44 / S 36 |
| Icon Button | `5:161` | Surface · Soft · Primary · Ghost × 48 / 40 / 32 |
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
| Category Tile | `9:159` | Desktop 136 × 187 · Mobile 80 × 100 |
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
