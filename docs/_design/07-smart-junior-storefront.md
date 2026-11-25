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

### 7.1 Colour: the Vivid mode on `SJ · Color` (mode id `34:0`) — values superseded by §15 (2026-11-24)

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

### 7.5 Footer: Concept E `Footer — Vivid · বাংলা · Concept E (Simple playground)` (`201:2882`) is live

**Live footer since 2026-11-25 (owner): Concept E, below.** The night-sky set described next, `41:9166` (Desktop `41:8217`, Mobile `41:8832`), is now `Footer — Vivid · বাংলা (backup 2026-11-25, night sky)` on the Components page and no screen uses it. Its notes are kept as history.

- scalloped wave top
- brand-colour blobs and the confetti layer, also inside the club card
- column headings in lemon / aqua / pink / blue
- colourful social buttons
- the logo mark peeking over the wave
- a five-colour rainbow stripe at the bottom

The Bangla copy is baked into this set, so it is a Vivid-only component. Until 2026-11-25 every footer instance on the Vivid page used it; the tablet used the Mobile variant stretched to 834.

**Redesign, "night-sky playground" (owner request 2026-11-23: more creative and kid-friendly).** The earlier version is kept as `Footer — Vivid · বাংলা (backup 2026-11-23)` (`102:1042`) on the Components page. No instance uses it.

- **Newsletter removed** (owner). The "Smart Junior ক্লাব" WhatsApp sign-up card is gone from both variants.
- **Sky.** The four dim blobs are replaced by a `brand/sun` crescent moon and four-point sparkles in the brand colours. The confetti layer stays, at 50% layer opacity in the footer (owner, 2026-11-23), so it sits further back than in other sections.
- **Mascot** says "আবার এসো!" in a speech bubble beside it on the wave.
- **Desktop column headings** are tilted sticker pills (±2–3°): শপ `brand/sun`, সহায়তা `brand/grow`, প্রতিষ্ঠান `brand/play`, যোগাযোগ `brand/learn`, with `text/primary` labels. Contact icons sit in rounded squares in the same four colours, with `icon/default` glyphs.
- **Mobile accordion rows** start with a tilted colour tile, and their "+" icons take the row colour.
- **"Toy town" band** (new frame between the columns and the bottom bar): aqua and blue hills, an A-B-C block stack, a ball, a kite and balloons tied to the hill. The front hill is `bg/brand-strong` and runs into the bottom bar, which now sits on that colour with `text/inverse` text and white-outlined payment chips (white on grape/700 ≥ 7:1).
- **Stretching.** The band's hills scale with the frame. Blocks pin to the left; balloons, kite, moon and bubble pin to the right, so the tablet (Mobile variant at 834) keeps its layout.
- Every new fill and stroke is bound to an `SJ · Color` token (no raw hex).

**Code note:** the band and sky are decorative. Ship them as one inline SVG per breakpoint with `aria-hidden="true"`, not as images, and don't animate them unless `prefers-reduced-motion` allows it.

**Concept B, "Toy blocks" (2026-11-23, alternative for the owner to choose).** It is component set `Footer — Vivid · বাংলা · Concept B (Toy blocks)` (`109:1796`: Desktop `108:1660`, Mobile `109:1689`) on the Components page. **No screen uses it.**

- **Light footer.** The background is `pastel/lilac`, with a party-bunting edge: flags in the five brand colours on a sagging string.
- **Blocks.** Each link group is a chunky toy block. Blocks have `text/primary` 2.5 px outlines, Lego studs on top and a hard, zero-blur 6 px shadow, whose colour is bound to `text/primary`:
  - brand on `bg/surface`
  - শপ on `pastel/butter-strong`
  - সহায়তা on `pastel/mint-strong`
  - প্রতিষ্ঠান on `pastel/blush-strong`
  - যোগাযোগ on `pastel/sky-strong`
  
  On desktop the blocks have different heights and stand on a `bg/brand-strong` shelf. The mascot sits on the Contact block. Links use `text/secondary`, never `text/muted`, because of the -strong pastel rule (§11).
- **Mobile.** The four link groups are accordion blocks, each with a white "+" button.
- **Wordmark.** A giant "Smart Junior" in Fredoka Bold: 200 px on desktop, 61 px on mobile. Each letter takes a brand colour, with an ink outline and a hard shadow. It is a logotype, so it is exempt from text contrast.
- **Bottom bar.** `text/primary` (navy) with `text/inverse` text.

**Concept C, "Toy train" (2026-11-23, alternative for the owner to choose).** It is component set `Footer — Vivid · বাংলা · Concept C (Toy train)` (`111:1959`: Desktop `110:1718`, Mobile `111:1747`) on the Components page. **No screen uses it yet.**

- **Day sky.** The background is `pastel/sky`. Its top edge is a row of `bg/canvas` cloud bumps, so the page above appears to melt into clouds. There is a `brand/sun` sun and white outlined clouds.
- **Headline.** "চলো খেলার দেশে!", with the subline "খেলনা, বই আর শেখার মজা — সব এক ট্রেনে।"
- **Train.** All content rides in a train with `text/primary` roofs, wheels with `brand/sun` hubs, and couplings. Each car body is a brand colour with a white window, so every link sits on `bg/surface` and contrast is the same as a plain card:
  - **engine:** `action/primary`, with a chimney, smoke and a lamp. It carries the logo, blurb and socials.
  - **carriages:** শপ `brand/sun`, সহায়তা `brand/grow`, প্রতিষ্ঠান `brand/learn`, যোগাযোগ `brand/lagoon`.
  
  The mascot rides on the last carriage.
- **Track and ground.** The train runs on a rail with `pastel/mint-ink` sleepers, over a `brand/grow` grass strip with small flowers. The bottom bar is `text/primary` (navy).
- **Mobile.** The engine runs on its own track. The four link groups are two two-carriage trains, each carriage an accordion with a "+" button.

**Concept D, "Treasure map" (2026-11-24, alternative for the owner to choose).** It is component set `Footer — Vivid · বাংলা · Concept D (Treasure map)` (`115:1932`: Desktop `114:1805`, Mobile `115:1805`) on the Components page. **No screen uses it yet.**

- **Sea.** The footer is a sea (`pastel/sky-strong`). Its top edge is a wavy `bg/canvas` shoreline, and white wave doodles are scattered over it.
- **Banner.** A ribbon reading "Smart Junior দ্বীপে স্বাগতম!" (`action/primary`, with `action/primary-hover` tails).
- **Map scrolls.** Brand info (logo, blurb, socials) and contact details sit on two parchment scrolls (`pastel/butter` paper, `pastel/butter-strong` rolls). The mascot peeks over the Contact scroll.
- **Islands.** The link groups (শপ, সহায়তা, প্রতিষ্ঠান) are islands: `pastel/butter-strong` sand on a `brand/grow` grass rim. They are drawn as organic squircle blobs, so the text never leaves the sand. Each island has a flag in its group colour (`brand/sun`, `brand/grow`, `brand/learn`).
- **Trail.** A dotted `action/sale` treasure trail runs from "শুরু এখান থেকে" under the brand scroll, past each island, to an **X** and "এখানে আমরা!" below the Contact scroll.
- **Extras.** A compass rose (top right) and a little sailing ship (bottom left).
- **Mobile.** The brand scroll comes first. Below it, the four groups (Contact included) are accordion islands that zigzag left and right, joined by the trail, which ends at the X.
- **Contrast.** All text sits on the parchment or sand fills, using `text/primary` and `text/secondary` (never muted).

**Concept E, "Simple playground" (2026-11-25, alternative for the owner to choose).** Owner request: "update the footer design with simple and kids friendly animated element". It is component set `Footer — Vivid · বাংলা · Concept E (Simple playground)` (`201:2882`: Desktop `200:2724`, Mobile `201:2753`) on the Components page, at x 10400 beside Concept D. The set and both variants set `SJ · Color` to Vivid. Preview instances, each in a wrapper frame that owns a 12 s timeline: Desktop `201:3401` (instance `201:2883`), Mobile `201:3402` (instance `201:3033`). **Live on every Vivid screen (owner, 2026-11-25); night-sky set kept as backup `41:9166`.** 59 instances swapped on `34:5658` (see below).

- **Simple first.** The background is `pastel/butter-strong`. Its top edge is a soft two-hump wave melting from `bg/canvas`. There are no stickers, tilts or pattern.
- **Columns (desktop).** Plain columns on a 1200 content width with a `space/40` gap:
  - brand (320): the Logo `Full, Light`, the blurb (`Body/M`, `text/secondary`) and four 40 px `bg/surface` social circles (Facebook, Instagram, YouTube, WhatsApp) with an `icon/default` glyph
  - শপ, সহায়তা, প্রতিষ্ঠান (fill): a `Label/L` `text/primary` heading over a 24 × 4 accent bar (`brand/sun`, `brand/learn`, `brand/play`), then `Body/M` `text/secondary` links. The labels are the live footer's.
  - যোগাযোগ করুন (260, `brand/lagoon` bar): four rows, each with a 36 px `bg/surface` icon tile. The values are the §7.0 placeholders in dashed `border/default` frames: `placeholder:hotline` [হটলাইন নম্বর] with `placeholder:hours` [সময়সূচি], "WhatsApp-এ অর্ডার" (no number), `placeholder:email` [ইমেইল] and `placeholder:address` [ঠিকানা].
- **Play strip** (desktop 1440 × 150, mobile 390 × 112). A `pastel/mint-strong` back hill and a `brand/learn` grass band run into the bottom bar. On it:
  - the mascot (Logo `Mark`, 56 px desktop and 44 px mobile) with a `bg/surface` speech bubble "আবার এসো!" (`SJ/Elevation/e1`)
  - two balloons (`brand/grow`, `brand/lagoon`) tied to the hill
  - a `brand/play` kite with `bg/surface` spars and two bows (desktop only)
  - sparkles (four-point stars in `brand/sun`, `brand/lagoon`, `brand/play`, `brand/grow`): five on desktop, three on mobile
  
  The strip sits under the columns, so no decoration crosses any text. The hills scale with the frame. The mascot pins left, the kite to the centre and the balloons to the right.
- **Bottom bar.** `bg/brand-strong` with `text/inverse`, as today: "© 2026 Smart Junior. যত্ন নিয়ে তৈরি, ঢাকায়।", "আমরা গ্রহণ করি" and five outlined chips: ক্যাশ অন ডেলিভারি, bKash, Nagad, Visa, Mastercard. The brand names are in Fredoka.
- **Mobile.** The brand block comes first, with 44 px social circles. The four groups are accordion rows (56 px or more, `border/strong` divider, a 10 px brand-colour dot, `Icon/Plus`). যোগাযোগ is drawn open (`Icon/Minus`) so the placeholders show. The strip has the mascot and bubble, the balloons and three sparkles. The bottom bar stacks, wraps its chips and keeps `space/120` bottom padding for the fixed tab bar (raised from `space/96` on 2026-11-25: the 110 px tab bar covered the second chip row).
- **Tokens.** All 150 fills and strokes in the set are bound to `SJ · Color` (0 raw). Padding, gaps and radii bind `SJ · Space` and `SJ · Radius`, and text uses `SJ Vivid/*` styles. The exceptions are the four Latin payment chips: their text is set entirely in Fredoka, so it binds no style (the §5.4 known limit). The top-level overlap audit on `2:4` found no intersections (50 nodes).
- **Contrast (Vivid).**

  | Pair | Ratio |
  |---|---|
  | `text/secondary` #334155 on `pastel/butter-strong` #FEF3C7 | 9.3:1 |
  | `text/primary` on the same | 13.1:1 |
  | `text/inverse` on `bg/brand-strong` #0369A1 | 5.9:1 |
  | `icon/default` and the bubble text on `bg/surface` | 14.6:1 |

  In Light, `text/secondary` on butter-strong is about 8.9:1. The social circles and icon tiles are white on butter (1.1:1). That is fine, because each glyph carries the meaning.

**Motion (Figma).** Real manual keyframes sit on the decorative layers of both variants, and instances inherit them. Every loop divides the 12 s timeline, so the timeline repeats seamlessly. An export at 5 fps confirmed the motion: the mascot tilts, the kite sways, the balloons bob out of phase and the sparkles twinkle. A footer instance placed inside a screen frame plays on that frame's timeline. Set the frame's timeline to 12 s; an instance alone stays at the default 2 s.

**Motion spec (code).**

| Element | Property | Range | Duration | Easing | Delay | Loop |
|---|---|---|---|---|---|---|
| Mascot | `rotate` (origin bottom centre) | 0 → 8° → −6° → 6° → −4° → 0 in the first 2.5 s, then still | 6 s | ease-in-out | 0 | infinite |
| Balloon 1 | `translateY` | 0 → −6 px → 0 | 4 s | ease-in-out | 0 | infinite |
| Balloon 2 | `translateY` | 0 → −5 px → 0 | 4 s | ease-in-out | −2 s (half a cycle out of phase) | infinite |
| Kite (desktop) | `rotate` (origin at the string tip) | 4° → −4° → 4° | 6 s | ease-in-out | 0 | infinite |
| Sparkles | `opacity` and `scale` | opacity 1 → 0.35 → 1, scale 1 → 0.7 → 1 | 3 s | ease-in-out | 0 / 0.6 / 1.2 / 1.8 / 2.4 s desktop; 0 / 1 / 2 s mobile | infinite |

Rules:
- Only `transform` and `opacity` animate. Nothing flashes: the fastest cycle is one twinkle every 3 s, well under 3 flashes a second.
- Under `prefers-reduced-motion: reduce`, everything is static (`animation: none`), in the resting pose shown in Figma.
- Ship the strip as inline SVG with `aria-hidden="true"`. Use CSS `@keyframes` only, with `animation-iteration-count: infinite`, and no JS animation library.
- The strip has a fixed height per breakpoint, so the animation causes no layout shift. Set `transform-box: fill-box` on each animated group so it pivots on itself.

**Swap onto the Vivid screens (done 2026-11-25).** The owner asked to make Concept E live.
- **59 instances** of `41:9166` on `34:5658` swapped with `swapComponent`: 29 desktop → Desktop, 29 mobile → Mobile, and the Home tablet → Mobile, filling 834. They render Vivid (the set carries the mode). The English page `0:1` keeps its own `Footer` `11:376`.
- **500 page stays phone-free** (owner, 2026-11-15). Desktop `182:35056` hides the four `Rows` children of Contact (the "যোগাযোগ করুন" heading stays). Mobile `182:35620` hides the যোগাযোগ `Panel` and swaps its `Icon/Minus` to `Icon/Plus`, so the row reads as closed. The 22 · Maintenance frames have no footer.
- Every changed frame hugs its content and has a **12 s timeline**, so the footer motion loops seamlessly.
- The absolute Bottom Tab Bar on Home and Category mobile is re-pinned to the frame bottom (vertical constraint MAX). Other mobile screens keep the tab bar in flow after the footer.
- Rows from 06 · Order confirmed down moved down (a frame grew or the gap fell under 300) so every row clears the tallest frame above it by at least 300, labels moving with their rows. The `34:5658` overlap audit is clean (94 nodes).
- `41:9166` is renamed `Footer — Vivid · বাংলা (backup 2026-11-25, night sky)`, kept on `2:4`, unused.

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
- **Art.** Nine components, `Category 3D/<Name>` (Components page, frame `77:1051`): Educational `77:1052`, Soft Toys `77:1054`, Books `77:1056`, Montessori `77:1058`, Art & Craft `77:1060`, STEM `77:1062`, Baby Care `77:1064`, Birthday `77:1066`, Gifts `77:1068`. Each holds one AI render (Figma `generate_image`), cut out to a **transparent 512 px PNG**: the near-white background is removed with an edge flood-fill and a feathered edge. The art used to be white-background renders with a MULTIPLY blend. Off-white showed as a faint square once the art popped out of the tile on hover, so they were replaced.
- **Size.** The `Icon` instance-swap property (`Icon#9:18`) now points at the 3D art. In the Default state it is 94 px on desktop (in a 128 px circle) and 56 px on mobile (in a 76 px circle). The cut-outs have no white margin, so the earlier 112 / 68 px sizes touched the circle and were cropped. On hover it grows to 136 / 82 px. All 48 tile instances on both pages (Light `0:1` and Vivid `34:5658`) were set by label. "Birthday Gifts" and "Gifts" now have their own art (cake, and gift boxes); before, both used the cake icon.
- **Caveats.**
  - The renders are placeholders, like the product photos. Production needs real transparent PNG or WebP files, which the CMS or the storefront would serve.
  - The cut-outs are transparent, so they also work on a dark background.
- **Name and count spacing.** The name and the count now sit in their own `Text` stack (desktop `78:1034`, mobile `78:1035`) with a 2 px gap bound to `space/2`. The gap from the tile to the name stays 12 px on desktop and 8 px on mobile. The desktop tile is now 136 × 177.
- **Hover state ("pop & go": the owner asked for something of our own, not a copy of the reference).** `Category Tile` has a `State` variant with the values `Default` and `Hover`: Desktop Hover `79:1034`, Mobile Hover `79:1041`. On hover:
  - **The circle morphs into the brand squircle.** This is the logo's shape: `radius/2xl` on desktop, `radius/lg` on mobile. The tint stays the same.
  - **The 3D toy pops out.** It becomes absolutely positioned, a little larger (136 px on desktop, 82 px on mobile) and lifted out of the top of the shape (−22 px desktop, −14 px mobile). The tile does not clip.
  - **Two sparkles pop in.** They use `Icon/Sparkle` and stand for "Play".
  - **A white "go" chip appears at the bottom-right.** It is `bg/surface` with `SJ/Elevation/e1` and an `Icon/ArrowRight`, and it says the tile is a link.
  - The name and subtitle do not change. There is no card and no shadow on the tile.

  **Per-category colour comes from a variable mode, not from fill overrides.** The collection `SJ · Category tint` (`87:1036`) has the modes Sky, Butter, Lilac, Mint, Blush, Coral and Lagoon. Its variables are:
  - `tint/fill`, which aliases `pastel/<x>-strong`; the tile uses it in both states;
  - `tint/ink`, which aliases `pastel/<x>-ink`; the sparkles and the chip arrow use it.

  Each tile instance sets its mode, and the mode carries across the variant swap. The variables alias `SJ · Color`, so Vivid resolves them automatically.

  The Default variants have a prototype interaction: while hovering, change to Hover with Smart Animate, ease-out, 200 ms. Clipping is off on the home category rows and their section wrappers on both pages, so the popped art is not cropped: Light `14:327`, `14:292`, `17:1446`, `17:1470`, `17:1500`, `19:2139`, `19:2162`; Vivid `34:5706`, `34:5708`, `34:5915`, `34:5917`, `34:5922`, `34:6041`, `34:6043`.

  **For the storefront:**
  - Tint: deliver it per category as a CSS custom property, `--tile-tint` / `--tile-ink`.
  - Shape: animate `border-radius` from 50 % to the squircle radius.
  - Art: move it with `transform: translateY(-14px) scale(1.06)` and a spring-like ease.
  - Sparkles and chip: fade and scale them in.
  - Keep everything under 250 ms and respect `prefers-reduced-motion`; under reduced motion, drop the lift and keep the shape change.
- **Reference categories and Bangla subtitles.** The home category row now uses the owner's reference set, in this order (tint in brackets):
  1. Educational Toys (butter)
  2. Learning Books (lilac)
  3. Montessori (mint)
  4. Birthday Gifts (blush)
  5. Gifts & Combos (coral)
  6. STEM & Science (sky)
  7. Art & Writing (lilac)
  8. Stock Clearance (blush)

  Stock Clearance has new art, `Category 3D/Clearance` (`83:1036`), a % price tag. The `Count` line is now a Bangla subtitle set in Anek Bangla Regular, on both locales; for example "শেখা ও খেলা" and "বই ও অ্যাক্টিভিটি". The item counts are gone. Names are now Bold: Plus Jakarta Sans Bold on the English page, Anek Bangla Bold on the Bangla page, with Fredoka SemiBold for Latin runs such as "STEM".

  Mobile and tablet tiles use the Mobile variant. It hides the subtitle and uses short names: Educational, Books, Montessori, Birthday, Combos, STEM, Art, Clearance.

  On the Bangla page, two reference subtitles would have repeated the title, so they were replaced:
  - "জন্মদিনের উপহার" has the subtitle "জন্মদিনের সারপ্রাইজ";
  - "আঁকা ও লেখা" has the subtitle "রং, পেন্সিল ও খাতা".

  **Open:**
  - The Bangla subtitle on the English page is a deliberate bilingual touch from the reference. In code it is still an i18n string per category, not hardcoded.
  - The category list itself, including Stock Clearance as a category or a sale collection, needs to match the real catalogue.

## 14. Product Card: no hover state (2026-11-23)

A hover state was designed (lift, brand border, photo zoom, Quick view chip) and then **removed at the owner's request**.

- `Product Card` (`9:146`) is back to a single `Size` variant (`Desktop` `9:60`, `Mobile` `9:103`). It has no `State` property, no prototype hover, and no `Quick view` property.
- **The card has no border at rest.** The old 1 px `border/default` stroke is gone; the card is set off by `SJ/Elevation/e1` alone. The stroke overrides that all 72 screen cards carried were cleared. Cards are 2 px shorter than before (desktop 448, mobile 319), because the old stroke counted in layout.
- **Kept from that work:** `SJ · Type` (`94:17161`), with the modes Light and Vivid. It holds:
  - `font/card-title`: Plus Jakarta Sans in Light, Fredoka in Vivid;
  - `line-height/card-title`: 21 / 24.15 px, at 15 px text;
  - `line-height/card-title-s`: 18.2 / 20.86 px, at 14 px text.

  Both card title layers are bound to these, and the Vivid page's 20 top-level frames set the Vivid mode. The values are in px on purpose, because a bound FLOAT is read as pixels (Figma rule 7).
- **For the storefront:** no hover effect on product cards. Keyboard focus still needs a visible `SJ/Focus/ring` on the card link.
- **Superseded in code (owner, 2026-11-24/25):** the storefront card does have a hover. Its shadow steps from e1 to e2 and the photo zooms 4% inside its frame. When the product has a second photo (API `hover_image`, the next gallery photo after the card's own), that photo fades in over the first. The card itself does **not** lift (no translate). Keyboard focus inside the card shows the same state. Under reduced motion the photo still swaps, but nothing zooms or animates. The Figma `Product Card` is unchanged and still has no `State` variant.
- **Site-wide motion (owner, 2026-11-25), CSS only:** links, buttons and fields ease their colour, border, shadow and opacity changes over 120 ms. In-page jumps scroll smoothly. The page's `<main>` rises 8 px into place on load. Each page section and product card fades up as it scrolls into view (`animation-timeline: view()`). All of this is off under `prefers-reduced-motion`. See `app/tailwind.css` in the storefront repo.
- **Every card is the same height (owner, 2026-11-23).** One-line titles used to make cards shorter: 427 instead of 448 on Light desktop, 301 instead of 319 on Light mobile, and similar gaps on Vivid. Prices and buttons then didn't line up across a row. The title is now a fixed **two-line box**: text auto-resize "truncate", ending in an ellipsis. Its height is bound to two new `SJ · Type` tokens:
  - `size/card-title-h`: 42 px in Light, 50 px in Vivid;
  - `size/card-title-h-s`: 36 px in Light, 42 px in Vivid.

  Measured on all 144 instances, every card is now one height per size and page:
  - Light: desktop 448, mobile 319;
  - Vivid: desktop 458, mobile 327.

  **For the storefront:** clamp the title with `line-clamp: 2` (`-webkit-line-clamp`) and give it `min-height: calc(2 * line-height)`. Give the card `height: 100%` inside a grid row, so a translated title that wraps differently never changes the card height.

**Superseded 2026-11-24 (owner):** the product card now has a hover state — a 2 px lift, the next elevation step and a 1.04 image zoom inside its frame, on motion tokens; pointer devices only (`@media (hover: hover)`), the same lift on keyboard focus, and no movement under `prefers-reduced-motion`. Storefront: `components/listing/ProductCard.tsx`, `app/tailwind.css`.

## 15. Vivid repalette: the Smart Junior brand colours (2026-11-24)

The owner gave the Smart Junior brand palette and asked for it to **replace the Vivid values**. Light is unchanged. Section 7.1's Coolors ramps (`grape`, `bubblegum`, `lemon`, `splash`, `aqua`) and the owner's #F15BB5 button choice (§7.1, §12) are superseded for Vivid. The ramps stay in `SJ · Primitives`, but no `SJ · Color` token references them any more.

| Colour | Hex | Purpose (owner) | Ramp in `SJ · Primitives` |
|---|---|---|---|
| Bright Blue | `#0EA5E9` | Primary brand | `blue/500` |
| Sunshine Yellow | `#FBBF24` | Highlights | `sunshine/400` |
| Playful Pink | `#F43F5E` | Promotions | `pink/500` |
| Fresh Green | `#22C55E` | Learning | `green/500` |
| Creative Purple | `#8B5CF6` | Creativity | `purple/500` |
| Energetic Orange | `#FB923C` | Call to action | `orange/400` |
| White | `#FFFFFF` | Neutral | `white` |
| Light Blue | `#F0F9FF` | Neutral | `blue/50` |
| Gray | `#64748B` | Neutral | `slate/500` |
| Dark Navy | `#1E293B` | Neutral | `slate/800` |

Every hex is an exact Tailwind value (sky, amber, rose, green, violet, orange, slate), so each ramp is that Tailwind ramp, steps 50–800 (slate to 900). Sixty-four primitives were added. The palette colour is at the step where Tailwind has it, so it is **not** always 500 (yellow and orange are `/400`).

**Owner decision (2026-11-24):** the main buttons use the orange CTA, and blue is the brand colour for surfaces, the hero and links.

Vivid aliases:
- **Action.**
  - `action/primary` → `orange/400`, hover → `orange/500`, soft → `orange/50`.
  - `text/on-primary` and `icon/on-primary` → **`white`** (owner, 2026-11-24; first set to `slate/800`, which is 6.5:1). White on #FB923C is **2.2:1, which fails WCAG AA** for text (4.5:1) and for the large-text and icon minimum (3:1). This is a known, accepted gap. It closes if `action/primary` moves to `orange/700` #C2410C (white 5.2:1), or `orange/600` #EA580C (3.6:1, large text only).
  - `action/cart` and `action/accent` → `blue/500`. The navy icon is 5.3:1, and blue stays clearly apart from the orange Buy now.
  - `action/sale` → `pink/600`, because white on `pink/500` is only 3.7:1.
  - `action/secondary` → `sunshine/400`.
- **Brand.** `brand/lagoon` → blue, `brand/play` → pink, `brand/learn` → green (the palette's *Learning*), `brand/grow` → purple (*Creativity*), `brand/sun` → sunshine/400. `bg/brand-strong` (hero) → `blue/700`, where white text is 5.9:1.
- **Neutrals.**
  - `bg/canvas` → `blue/50` (Light Blue), `bg/surface` → white, `bg/subtle` → `blue/100`.
  - `text/primary`, `icon/default` and `bg/inverse` → `slate/800` (Dark Navy). `text/secondary` → `slate/700`.
  - `text/muted` → **`slate/600`, not Gray**: Gray #64748B on the Light Blue canvas is 4.47:1, just under AA. Gray is used for `icon/muted` and `border/input` (≥ 3:1 on both backgrounds).
- **Text on white.** `text/brand` → `blue/700`, `text/sale` → `pink/700`, `text/success` → `green/700`.
- **Pastels.** coral → orange, sky and lagoon → blue (100/200/800, because blue/50 is the canvas and a tile in it would disappear), butter → sunshine, lilac → purple, blush → pink, mint → green (50/100/800).
- **Effects.** focus ring `#0369a1`, glow-primary orange at 28%, glow-accent blue at 28%.

The screens on page `34:5658` re-render through the variables, and nothing on the canvas was repainted. The §8 contrast audit was not re-run for the new values; the ratios above are the ones that were checked.

**White icons on cart and social buttons (owner, 2026-11-24).**
- **Cart.** There are two new tokens, **`icon/on-cart`** and **`text/on-cart`**: Light `ink/900`, Vivid `white`. All 75 add-to-cart controls on page `34:5658` were rebound from `icon/default` / `text/primary` (77 paints, Vivid page only; the Light page is unchanged). White on `blue/500` is **2.8:1**, just under the 3:1 needed for icons.
- **Footer social buttons.** In the Vivid-only footer (`41:8145` desktop, `41:8796` mobile), the icons are now bound to `icon/inverse` instead of `text/primary`. All 9 footer instances follow. White icons on the brand circles:

  | Circle | White icon |
  |---|---|
  | Pink | 3.7:1 |
  | Purple | about 4.2:1 |
  | Green | about 2.3:1, fails 3:1 |
  | Yellow | about 1.7:1, fails 3:1 |

  The owner accepted this.

**Playful Pink carries promotions (owner, 2026-11-24).** Until now #F43F5E itself barely showed in Vivid: only dark shades and pale tints were in use. The following Vivid aliases now point at pink:

| Token | Now | Was |
|---|---|---|
| `action/sale` | `pink/500` | `pink/600` |
| `action/accent` | `pink/500` (header "Offers" button, cart and wishlist count badges) | blue |
| `text/sale` | `pink/600` | `pink/700` |
| `pastel/coral`, `pastel/coral-strong`, `pastel/coral-ink` | `pink/50`, `pink/100`, `pink/700` (Flash deals band, flash-sale promo card, sale eyebrows) | orange tints |
| `effect/glow-accent` | `#F43F5E` at 28% | blue |

- **Sale badges.** The 72 "-NN%" Sale badges on page `34:5658` were bound to the primitive `coral/700`, a rule-26 defect in the Badge component. On the Vivid page they are overridden to `action/sale`. The Light page and the main component still bind `coral/700`; fix the component when it is built in code.
- **Contrast.** White on #F43F5E is **3.7:1**, which passes for large text and icons but not for the small bold badge labels (4.5:1).

**Darkened for the contrast gate (owner, 2026-11-24, before building).** The token build fails on any AA failure, so the owner chose to keep white labels and darken the fills. In Vivid, both here and in code:

| Token | Now | Contrast with white |
|---|---|---|
| `action/primary` | `orange/700` #C2410C (hover `orange/800`) | 5.2:1 |
| `action/cart` | `blue/700` #0369A1 | 5.9:1 |
| `action/sale` and `action/accent` | `pink/600` #E11D48 | 4.7:1 |

The lighter tints, the confetti and the flash-sale band keep the brighter palette.

## 16. Code: the `smart-junior` theme (2026-11-24)

Code follows the plan in §5, with one change: the storefront keeps its existing semantic vocabulary (`bg-brand`, `text-fg-primary`, `bg-tile-*`, `deco-*` …) instead of adopting the `SJ · *` names.

- **Tokens.** The Toy Mart library (`PWZp5VzEgEEf5N2Y38lGIh`) has a fifth `Color` mode, **Smart Junior**. It aliases the new primitives `sj-blue|sunshine|pink|green|purple|orange|red|slate/*` and uses the effect styles `Elevation/Smart Junior/e1–e4`. The export runs through `packages/tokens` as `data-theme="smart-junior"`, and the contrast gate covers it (150 pairs pass). The admin themes are unchanged.
- **Mapping.**

  | Storefront token | Smart Junior colour |
  |---|---|
  | `brand` | orange/700 (the CTA) |
  | `text/brand` and `link` | blue/700 |
  | `teal` | blue |
  | `accent` | sunshine |
  | `grape` | purple/600 |
  | `discount` and `bg/pink` | pink/600 |
  | `tile-*` | the six palette 100 tints |
  | `deco-*` | the palette 400/500 colours |
  | `danger` | a red ramp, kept apart from sale pink |
- **Fonts.** The storefront uses Fredoka for Latin text and digits, Anek Bangla for Bangla text and Hind Siliguri for Bangla titles (§7.2). Hanken Grotesk and Baloo Da 2 are gone. Hind Siliguri stops at 700, so the two 800 styles render at 700.
- **Name.** The storefront fallback copy, SEO titles and the logo say Smart Junior. Invoices, emails, the admin and the legal name in `lib/site-config.ts` still say Toy Mart BD (owner, 2026-11-24). The site name a visitor sees comes from Admin › Settings › Site & branding. If that is blank, the storefront shows the business name (Toy Mart BD), so the owner must set Site name = Smart Junior there.
- **Built so far (storefront repo).**
  - The theme and fonts.
  - The header: ink announcement strip with track, help and the EN | বাংলা switch; the logo; the search pill with an orange button; hotline; wishlist and account; and the cart pill, which shows the API's `totals.grand_total` from a readable `tm_cart_total` cookie the cart handlers keep current.
  - The category pills, with icons, "All categories" and a pink "Offers & deals".
  - The mobile header with its search row.
  - The bottom tab bar: Home · Shop · raised cart · Wishlist · Account. Search is now in the header row.
  - The night-sky footer: art exported from Figma and recoloured to tokens, mobile accordions.
  - The home sections, product card, PDP, cart and checkout are still the Toy Mart look.
- **Deviations from Figma, and why.**
  - The announcement strip shows one message, the CMS banner or the COD promise, not three. "Free over ৳1,500" and "7-day returns" are business values the owner has not confirmed.
  - The footer "We accept" shows cash on delivery only, until the online gateways are live.
  - The footer sticker headings on purple and pink use the 600 fill with white text: ink on the 500s fails AA.
  - The footer contact subtitles keep the sample hours and address (`data-placeholder`).
  - The CMS footer and header menus in the database still have Toy Mart wording; that is admin content.

### 16.1 Home sections (2026-11-24)

These blocks are restyled to the Vivid frames on page `34:5658`, and the built-in fallback home matches them:
- **Hero (2026-11-24, exact pass).** The deep-blue card with confetti, the yellow eyebrow chip, the headline with its `accent` words in sun, the orange CTA plus the white outline second button, and photo dots. Copy 440 | photo at 1440, copy 380 | photo from 768, photo on top below. Beside it at 1440 sits a 400-wide column of up to two mini promo cards (eyebrow, title, outline button, image multiplied into a pastel `tone`); below 1024 they sit under the card in two columns (no button; the card is the link). The parents row (avatars, stars, rating, line) shows at 1440 only. All of it is hero-block data (`promos`, `proof`, `accent`, `cta2_*`, docs/api/cms-blocks.md); nothing is defaulted, so the figures appear only when the owner enters them.
- **Trust strip.** Tinted icon chips. On mobile it is a card scroller.
- **Category tiles (2026-11-24, exact pass).** The mint eyebrow chip, the heading and its line (block `eyebrow` / `sub`), no "see all" (switch it off on the block). Pastel circles in the Figma order (Butter, Lilac, Mint, Blush, Coral, Sky, Lilac, Blush) that morph into a squircle on hover; the art is the category's **image**, picked from the Media Library in Catalog › Categories (`image_media_id`, a ~320 px rendition; the legacy `image_path` is the fallback; without either, the icon). Under the name from 1024 sits the category's **short line** (`tagline` / `tagline_bn`, also on the category). One row of 8 from 768, 4-up below. Deviations: Figma's sky tint for this row is `#BAE6FD`, the storefront `tile-sky` is `#E0F2FE`; the eyebrow chip uses `tile-mint` (`#DCFCE7`) where Figma has `pastel/mint` `#F0FDF4`; the heading is 34 px (`t-h1`) where Figma has 32. The 3D art in Figma (`77:1051`) is placeholder AI art; the owner uploads the real images per category.
- **Age tiles.** Tinted cards with a "go" chip.
- **Product card.** No border and no hover; a rating row from the API; a two-line title box; a blue cart square followed by the orange "Buy now"; a pink sale badge. This applies sitewide.
- **Section header.** An optional eyebrow, a subtitle and an orange "see all" pill.
- **Flash deals (2026-11-24, exact pass).** The pink band with confetti; the header with the pink chip, the heading and the block's `sub` line (Figma "প্রতিদিন সকাল 10টায় দাম কমে — স্টক থাকা পর্যন্ত।"; without one, the sale's end time), "See all", the 48 px square prev / next and the countdown pill. From 1024 the cards are a 4-up slider driven by those header arrows (dimmed and disabled when every card fits, as Figma shows them); below 1024 the swipe rail with the countdown under the header. Deviation: the band is `tile-pink` `#FFE4E6`, Figma `pastel/coral` `#FFF1F2` (no storefront token).
- **Journal and blog cards, Help CTA / chat band, Promo banner, Testimonials.** Restyled to the Smart Junior card language.

Hero deviations still open: the page gutter is 80 px at 1440 (Figma 120) and 24 px at 834 (Figma 32) — a sitewide container value, not a hero change; the card radius is 32 (Figma `radius/2xl` 36 has no storefront token). The hero's promo and proof values on the local database are the Figma sample copy; the owner must enter the real promotions and figures in Pages › Home.

### 16.2 Every home section from block content (2026-11-24)

Owner rule: every word, link, image and figure on a home section is a block field edited in Pages › Home; the storefront draws no copy of its own beyond generic controls (arrows, "Play", the "New" badge, a fallback when an optional label is left empty). Contract: `docs/api/cms-blocks.md`.

| Figma section | Block | What the editor sets |
|---|---|---|
| Section header (every rail) | shared fields | `eyebrow`, `eyebrow_tone` (pink · green · blue · yellow · purple · orange), `title`, `sub` |
| Shop by age `34:5735` | `age_tiles` | header; up to 8 photo cards (title, line, link, photo, tone). No cards = the 7 live age bands |
| Bestsellers `34:5742` | `product_rail`, `layout: grid` | header; up to 6 category chips (`chip_category_ids`, first chip `all_label`) — the server runs the rule inside each category; the "more" button text (`see_all_label`) |
| New arrivals `34:5779` | `product_rail`, `layout: slider` | header; see-all + prev/next in the header |
| Gifts & combos `57:12772` | `product_rail` with `band: pink` | any rule (a gift category or a manual pick) on a full-width pastel band |
| Promo banners `34:5763` | `promo_banners` (new) | 1–2 banners: eyebrow, title, line, button, photo, tone |
| Why Smart Junior `34:5786` | `why_band` (new) | eyebrow, title, paragraph, up to 4 figures typed by the owner (never defaulted), photo |
| Video reel `55:12790` | `video_reel` (new) | header, band, see-all; up to 12 reels: video link that plays in a popup on the page — YouTube (via youtube-nocookie.com), Facebook, Instagram, or a hosted .mp4/.webm; the player loads only on play, 9:16 poster, title, length (read from YouTube or the file after save; a typed one overrides; Facebook/Instagram are typed), views caption, optional product chip (listable products only) |
| Journal `34:5851` | `blog_teaser` | header, see-all switch (→ `/blog`) |
| Help CTA `34:5872` | `chat_band`, `tone: yellow` | title, line, WhatsApp button text, hotline button switch (both numbers come from Settings › Store › Business and stay hidden while they are the sample) |
| Product card "নতুন" | API `is_new` | published within `catalog.new_badge_days` (default 30) |

The local database carries the Figma sample copy and AI placeholder photos for these sections (version 8 of the home layout); the owner replaces the figures, videos and photos in Pages › Home. The `testimonials` block is no longer on the home layout; the reel took its place (§10).

Remaining deviations (storefront):
- **Headers.** Section headings are 34 px (`t-h1`) against Figma's 32. The pink eyebrow and the gifts band use `tile-pink` #FFE4E6 against Figma #FFF1F2. Purple text is `grape` #7C3AED against Figma #6D28D9.
- **Age cards.** Titles are 27 px against 24. The sky card uses `tile-sky` #E0F2FE against Figma #BAE6FD.
- **Promo banners.** Radius is 32 against 36. The card grows to fit three-line Bangla titles, where Figma has a fixed 300 px height.
- **Why band.**
  - Stat tiles are `surface/12` on navy; Figma uses ink/700, which has no token.
  - Values use the deco tints.
  - Radius is 32.
- **Reel.**
  - Only the poster is the video link, so it is not nested with the product-chip link.
  - The sub-line stays visible on mobile.
  - Product names follow the page language.
- **Bestsellers on mobile.** The header link shows the editor's `see_all_label`.
- **Help CTA on the phone.** The buttons show the hotline number and the editor's label, not Figma's short "কল করুন" / "WhatsApp".

### 16.3 Category, product, cart, checkout and order confirmed (2026-11-24)

Restyled to the Vivid frames on page `34:5658` (category `34:6106` / `34:6313` / sheet `34:6359`, product `34:6394` / `34:6677`, cart `34:6773` / `34:6922`, checkout `34:7021` / `34:7187`, order confirmed `34:7265` / `34:7307`). Behaviour is unchanged: no-JS forms, idempotency, the address cascade, tracking, JSON-LD, the sticky bars.

**Shared pieces.**
- `components/layout/MobileTopBar` and the `PageShell` `mobileTopBar` slot. Below lg the top bar replaces the site header and carries the page's h1; from lg the site header is back. It is used on the cart ("Your cart (n)"; back to home), the product (product name; back to its category) and checkout (back to the cart, a trailing "Secure" lock).
- The icon set has `lock`, which is used on the cart and checkout CTAs.
- e2e specs find the page title with `getByRole('heading', { level: 1 })`. The DOM holds two h1s and only one is displayed at a time.
- The ListingView restyle also applies to brand, search, all-toys and flash-sale listings.

**Deviations common to all five pages.**
- Hairlines and card borders use `line-default` #E2E8F0, because Figma's `border/subtle` #F1F5F9 has no storefront token.
- Radius 36 (`radius/2xl`) renders as 32 (`xl-plus`).
- Headings run one step larger than Figma: `t-h3` is 21 against 20, `t-h1` is 34 against 32/36, and `t-price-lg` is 30 against 28.
- The butter and mint pastels use the `tile-*` 100 tints, not Figma's 50 tints.

**Category.**
- The hero lead is the category `tagline`, and the About card is built from the category `description`. Its heading is an i18n template ("Choosing {category} in Bangladesh").
- The collage is the first three product photos and is decorative.
- Pagination stays cursor "Show more", where Figma shows numbered pages.
- The price filter uses the API's price bands, not a slider.
- The sheet's Apply button says "Show results", without a count.
- Left out, no data: the average rating and "dispatched in 24 h" facts, the Skill and Rating facets, the "on sale" and "new" availability options, the grid/list toggle and the inline promo strip.

**Product.**
- The tabs include "Age & safety". Reviews is a link beside the tablist.
- "Buy now" has no "— pay on delivery" suffix, because online payment exists.
- The delivery card lists the API's three zones and makes no "Free" claims.
- The sticky bar has no stepper and no heart.
- The free-delivery line comes from the API threshold. There is no progress bar, which would need maths in the client.
- Left out:
  - The bestseller badge, gift chip, sold count, video tile and share button.
  - The skills tiles and the "What's in the box" list.
  - ~~Frequently bought together~~: built 2026-11-25, see §16.4.
- The section eyebrow is uppercase in English (`t-overline`); Figma uses sentence case.

**Cart.**
- The coupon field is always open.
- The free-delivery meter uses the API's `delivery.free_shipping`.
- The phone help card is hidden while the hotline is the sample.
- The "Delivery to" district picker is kept, although it is not in Figma.
- The mobile top bar has no "Edit", because the cart has no edit mode. The footer and the tab bar stay on the mobile cart.
- Left out:
  - ~~The cross-sell rail `34:6914`~~: built 2026-11-25, see §16.4.
  - Select-all and save for later, gift wrap, the line age badge, "reserved for 30 minutes" and a separate product-discount row.
- In Bangla the payment chips read বিকাশ / নগদ / রকেট (`payment.channels`), against §7.3, which keeps brands in English.

**Checkout.**
- There is no division select (the API has none) and no Express option (one quote per address).
- The delivery note (`customer_note`) is now on the page.
- On mobile the summary and reassurance cards sit after the form.
- The coupon button keeps the shared pill shape.
- Left out: "Most popular", the gift-wrap row, "incl. VAT" and "cancel free until shipped".
- The Figma copy about WhatsApp updates is not used. The existing call/SMS copy stays until WhatsApp order updates are live.

**Order confirmed.**
- The heading has no customer name.
- The middle fact tile shows "Placed on {date}", because the order has no delivery estimate.
- The account nudge drops "৳100 off your next order", an unconfirmed value.
- The items, delivery details and help cards are kept below the confirmation card.
- The thank-you line keeps "we will call to confirm, then SMS", for the same reason as checkout.

### 16.4 Recommendations on the product page, cart and order confirmed (2026-11-25)

The blocks come from the API's resolver (`docs/api/recommendations.md`), which applies every guardrail server-side (PRD §3.1a, rules 25b–25d). Every price, delta and bundle total is the API's. Each add from a block posts its placement as `recommendation_source`. Views, clicks and add-to-cart submits go to `POST /api/recommendation-events` (a small client island; the rails are server components). Nothing on checkout, and a test asserts it.

**Product page** (`34:6394` / `34:6677`).
- **Upgrade to** (≤ 2, in the buy-box column): no Figma frame. Compact cards with a "+৳delta" chip.
- **Frequently bought together** (`34:6643`): each item's API `unit_price`, the API `bundle_total`, and one "Add all N to cart" form that works without JS (the cart route handler adds up to 3 `variant_id`s in turn).
  - No struck-through total and no "You save" line: the API sends no saving figure. The subtitle drops Figma's "10% off".
  - The ticks are display only; the set is added whole.
  - Items are top-aligned. The border is `line-default`, the radius 32, and the total panel `tile-mint` (100 tint).
  - There is no mobile frame. Below 1024 the items sit three across over the total panel, without ticks.
- **Goes well with** (≤ 4, pink eyebrow) and **Next stage** (≤ 4, purple eyebrow): the related-rail layout. Next stage has no Figma frame.
- "You may also like" leaves out anything shown above it.

**Cart** (`34:6914`).
- The heading is `t-h2`: 27 px from 1024 against Figma's 24.
- The shared ProductCard keeps its age pill.
- 4 cards from 1024. The API sends up to 8; the rest show only in the mobile swipe row.
- The rail sits below the whole cart, so on mobile it comes after the summary, destination and help cards.

**Order confirmed.**
- No Figma frame. Up to 4 cards in the cart rail's language, last on the page, inside the 760 px column.

**Copy to review.** These Bangla strings are drafts: the Upgrade title "আরেকটু ভালোটি নিন" and the order rail title "ওর আরও যা ভালো লাগতে পারে".


### 16.5 Inner pages and the Concept E footer (2026-11-25)

Frames 07–31 (`docs/_design/08-sj-inner/00-shared.md` §6.1, §7.1, §8.1) and the Concept E footer (`201:2882`) are coded. Behaviour is unchanged: the same routes, no-JS forms, API calls, cookies, redirects, JSON-LD and tracking rule (number + phone, a failed lookup never says which field).

**Shared building blocks** are in `components/ui/`: Notice, StatusPill, OrderTimeline (+ TimelineCourier), EmptyState, Breadcrumb, PageHeaderBand, SegmentedLinks / SegmentedRadios, Switch, Select / FieldHelp / FieldError, `field-styles`, Avatar and Card. They are all server components. Token mapping for the pastels without a storefront token:

| Figma | Code |
|---|---|
| `bg/muted` | `bg-subtle` |
| sky | `tile-sky` |
| mint | `success-subtle` |
| blush | `tile-pink` |
| butter | `accent-subtle` |
| lilac | `tile-lavender` / `grape-subtle` |
| coral | `tile-peach` / `brand-subtle` |

**Footer (Concept E).**
- The structure is the wavy butter band, then four link columns with accent bars, then contact tiles, then the play strip, then the deep-blue bottom bar.
- The play strip is inline SVG, `aria-hidden`. Its motion is CSS keyframes only (wave, bob, sway, twinkle) and computes to `none` under reduced motion. It adds no client JS.
- Below 1024 the link groups are `<details>` accordions.
- The night-sky art is removed. `FooterArt` keeps only `Sparkle` and `ConfettiPattern`.
- Deviations:
  - "We accept" shows COD only.
  - The address row is shown only once the owner sets one.
  - The social circles come from the `social` config.
  - Lagoon falls back to `deco-sky`.
  - The 500 page drops the whole contact column (`showContact={false}`).

**Bugs and open questions, as coded.**
- B1: the 500 page and global error show no phone. The header hotline slot hides; there is no call button.
- B2: order detail shows "Pay now" when `payment.retry_allowed`.
- B3: the brands index shows `logo_url`; without one, a monogram tile. The brand page hero carries the same tile (new `logo` slot on ListingView / ListingHero).
- O3: courier names stay English in Bangla (`courierLabel`).
- O4: the account inner pages use the cart-style mobile top bar. Verify keeps the full header.
- O5: confetti appears on list and info headers only.
- O6: the delivery-charge page puts the estimator and the zone table side by side at 1440.
- O7: delivery details on mobile tracking stay hidden.
- O8 / O9: copy and the "forgot" targets are as shipped.
- O10: an unpaid online order reads "Payment pending".
- O11: address cards add the division unless it repeats the district.
- O12: the 404 button goes to `/toys`; the empty-brand button reads "All brands".
- O14: suggestion prices are `fg-primary`, and sale pink only with a `compare_at_price`.

**No-phone copy while the hotline is the sample.** These keys keep error and help text from naming a placeholder: `track.tooManyNoPhone`, `payment.reasonsNoPhone.*`, `notices.failedNoPhone`, `verify.helpBodyNoPhone`, `maintenance.bodyNoPhone`. `order.helpBody` loses the unconfirmed hours. Maintenance shows variant B (no contact) until a real hotline is set.

**Deviations common to the pages** (as §16.3):
- Radius 36 renders as 32.
- Hairlines use `line-default`.
- Headings run one step larger than Figma.
- The container is 1280 with 80 px gutters (Figma: 1200 with 120).
- Fields are 48 tall (Figma 52 on contact).

**Deviations by page.**
- **Sign-in pages.**
  - The phone bottom tab bar still shows; spec §0 says none.
  - The wrong-code boxes render empty, because the code is never echoed.
- **Account area.**
  - The tab bar highlights from the path (Wishlist on `/account/wishlist`).
  - The top-bar title is body-strong.
  - The empty states are a local `AccountEmpty` with a pastel disc per page.
  - Not built (no API): invoice, returns, reorder and back-in-stock.
- **Track and payment.**
  - The help card stays peach, and the items heading has no count. Both are the shared order-confirmed parts.
- **Blog.**
  - No Messenger share: it needs a Facebook app id. Facebook and WhatsApp are plain links; copy-link is a small island.
  - No guide contents list (not drawn).
- **Search and brands.**
  - Recent-search chips keep the ✕ on every chip.
  - No counts on brand or category cards (no API).
- **Static pages and delivery charge.**
  - The estimator still collapses its selects after a quote (shared with the PDP).
  - "More from" on the FAQ page appears only when the route passes the pages.

**Not covered by the mock API**, so checked on fixtures rather than in e2e:
- filled blog and guide pages
- section-built FAQ, Contact and stacked pages
- saving an address (`POST /account/addresses` has no mock handler)
- "Pay now" (every mock order is COD)

## 17. Logo palette (2026-11-25)

The owner added the new Smart Junior logo to the Foundations page: an image layer `SmartJunior 1` (`245:36323`) above `Frame 1` (`245:36322`). It is a raster board (2018 × 4096) showing the logo on cream and on dark navy, plus a colour strip. The colour tokens now follow the logo. Hexes were sampled from a full-resolution render of the strip and wordmark.

| Logo colour | Hex | Ramp | Pinned step |
|---|---|---|---|
| Sunshine yellow | `#FEC303` | `sunshine` | 400 |
| Blue | `#008CF5` | `blue` | 600 (was 500) |
| Navy | `#032360` | `slate` | 800 |
| Night (dark background) | `#000F26` | `slate` | 900 |
| Orange | `#FE611C` | `orange` | 500 |
| Pink | `#FD375A` | `pink` | 500 |
| Green | `#55BB2B` | `green` | 500 |
| Purple ("Smart") | `#591C9D` | `purple` | 800 (was 500) |
| Cream (light background) | `#FFFCF5` | `cream/50` (`#FFFCF7`) | — |

**How the ramps were built.**
- The six brand ramps in `SJ · Primitives` are rebuilt in OKLCH:
  - **Lightness** is the Tailwind step's own value, so a step keeps its contrast with white or navy.
  - **Hue** is the logo's own hue. Only yellow keeps Tailwind's hue drift, so its dark steps don't turn olive.
  - **Chroma** is scaled by the logo colour's chroma.
- Each logo colour replaces the step nearest to it in lightness, so blue lands at 600 and purple at 800.
- `slate/800` and `slate/900` take the logo navy and night. The rest of slate is unchanged. Light mode does not reference these ramps, so it is unchanged.

**Vivid alias changes:**
- `bg/canvas` → `cream/50`
- `brand/lagoon` and `border/brand` → `blue/600`
- `brand/grow` → `purple/800`
- `effect/focus-ring` → `#0264B2`
- `effect/glow-primary` → `#C0440A` at 28%
- `effect/glow-accent` → `#FD375A` at 28%

Every other alias keeps its step and picks up the new hue.

**Key ratios:**
- white on `action/primary` (`orange/700` #C0440A): 5.2:1
- white on `blue/700`: 6.0:1
- navy on cream: 14.5:1

The `SJ/Elevation/*` styles are raw `#1e2140` and were not changed.

**Code.**
- `sj-*` in `packages/tokens/src/figma-variables.json` is re-exported from Figma, and `sj-cream/50` is added.
- In the `smart-junior` mode:
  - `color/bg/canvas` → `sj-cream/50`
  - the logo inks: `deco/coral` → `sj-orange/500`, `deco/sky` → `sj-blue/600`, `deco/grape` → `sj-purple/800`
- The AA gate passes all 150 pairs.

**Not done.**
- The storefront `Wordmark`/`LogoMark` still draws the old squircle mark. The new logo exists only as a raster, so it needs an SVG source before it can replace them (open question §6, "Real assets").
- The Figma header and footer Logo component (`5:51`) is also still the old mark.

### 17.1 Buttons, hero and cart (owner, 2026-11-25)

The first logo pass changed only the dark, readable shades, so the site barely changed. The owner then chose the logo colours themselves, with these readable combinations:

**Buttons are pink** (owner: "change the button color with pink").
- The fill is `pink/600` `#E40F47` with white text, 4.7:1.
- The exact logo pink `#FD375A` fails with both white (3.6:1) and navy (4.2:1).
- Hover is `pink/700`.
- Code: `bg/brand`, `bg/brand-shelf`, `bg/brand-subtle(-shelf)` and `border/brand(-subtle)` → `sj-pink/*`.
- Figma Vivid: `action/primary`, `-hover` and `-soft` → `pink/600`, `/700` and `/50`; `effect/glow-primary` → `#E40F47` at 28%.
- "Buy now" and the "অফার ও ডিল" button are now the same pink.

**The hero, the footer bottom bar and blue cards use the logo-sky blue `blue/500` `#399DFE`, with navy text (5.3:1).**
- The exact logo blue `#008CF5` reaches only 4.3:1 with navy and 3.5:1 with white.
- The headline's accent word is the logo purple (`deco/grape`), because sun yellow on that blue is 1.75:1.
- Code: `bg/teal` → `sj-blue/500` and `text/on-teal` → navy. `HeroBlock`, `HomeHero` and the `SiteFooter` bar now use `text-fg-on-teal`, and the footer's locale switch uses the new `segment-on-teal` variant.
- Figma Vivid: `bg/brand-strong` and `action/cart` → `blue/500`, `text/on-cart` and `icon/on-cart` → navy.

**Cart buttons are an orange tint with an orange border** (owner).
- The background is the logo orange `#FE611C` at 12%, with a 1.5 px `#FE611C` border (3.0:1 on white, which meets the 1.4.11 minimum) and a navy icon and label.
- The hover tint is 20%.
- The border was darkened on request (owner: "border bit light") to the new `border/cart` token: Vivid `orange/700` `#C0440A`, 5.2:1 on white. The other modes alias `border/brand`. The token is in Figma `SJ · Color` and in the code export, and the AA gate checks it.
- This covers the product card square, the PDP cart button and "add all" in Frequently bought together.
- Code: `deco-coral` utilities in `CardCartButton`, `product/buttons.ts` and `FrequentlyBoughtTogether`.

**Button corners are smaller** (owner).
- Regular buttons go from `radius/md-minus` (14) to `radius/sm` (10).
- The card cart square goes from 10, and 14 at `lg`, to 8, and 10 at `lg`.
- Fields, cards and images keep 14.

**Cart button in Figma (2026-11-25).**
- `action/cart` (Vivid) aliases a new primitive, `orange/alpha-12` (#FE611C at 12%). The Light value is unchanged.
- All 129 cart controls on page `34:5658` got a 1.5 px inside stroke bound to `border/cart`: 126 card squares, the PDP cart row button, the mobile sticky-bar button and "add all" in Frequently bought together.
- Their labels and icons already bind `text/on-cart` and `icon/on-cart`, which are navy.

**Contrast.** Each check composites the tint over the background the control actually sits on: `bg/surface` (127 controls), `bg/canvas` (1) and `pastel/mint` (1).

| Pair | Ratio | Needed |
|---|---|---|
| Navy label and icon on the tint | 12.4–13.0:1 | 4.5:1 |
| Border against the page | 4.9–5.2:1 | 3:1 |
| Border against the tint | 4.3–4.5:1 | 3:1 |
| White on Buy now pink | 4.7:1 | 4.5:1 |

**Side by side.** In Product Card (`9:146`), the cart square now matches Buy now:
- 44 × 44 on desktop (was 40) and 36 × 36 on mobile (was 32)
- both bound to `radius/sm`; Buy now on desktop was `radius/md`

All 126 card instances follow, with no height mismatch. Code: `CART_SQUARE` is `size-9 lg:size-11 rounded-sm`, measured at 36 / 44 px and 10 px, the same as Buy now.

**Figma is still behind code on these points:**
- the hero and footer-bar text is still bound to `text/inverse` (white on the lighter blue)
- the Button radius outside the product card is still `md`

**Product card hover border (owner, 2026-11-25).**
- Product Card (`9:146`) has a new `State` property:
  - `State=Hover` (`258:3063` desktop, `258:3079` mobile) adds a 1.5 px inside stroke and `SJ/Elevation/e2`.
  - The stroke binds the new `border/card-hover` variable: Vivid `pink/600`, Light aliases `border/brand`. A new variable is needed because Figma's Vivid `border/brand` is blue, while code's is pink.
- The Default variants carry a prototype `ON_HOVER → CHANGE_TO` Hover, smart-animated over 320 ms on the standard curve.
- Code: the card has a transparent 1.5 px border at rest, so nothing shifts on hover.
  - On hover and keyboard focus: `border-line-brand` and `shadow-e2`.
  - The transition covers `box-shadow` and `border-color` on `--tm-duration-slow` with `ease-standard`. It is off under reduced motion.
