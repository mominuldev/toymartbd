# 08 · 05 — Browse and info pages (Smart Junior, Vivid · বাংলা)

**Status:** spec for the Figma build, not yet drawn · **Written:** 2026-11-25 (batch brief `docs/_handoff/briefs/brief-p10-sj-inner-pages.md`, agent 5)
**Target:** file `2b6PVOOapPbcaKYIdHWZup`, page `Smart Junior — Vivid · বাংলা` (`34:5658`). Every frame sets `SJ · Color` (and `SJ · Type`) to **Vivid**.
**Source read:** storefront `app/[locale]/{search,brands,categories,about,contact-message,[slug],delivery-charge}`, `components/{search,content,faq,listing,delivery}`, `components/StaticPage.tsx`, `lib/i18n/{en,bn,faq}.ts`, API schema `StorefrontBrand` / `StorefrontCategoryNode` / `DeliveryRates`, and the seeded sections in `database/seeders/StaticPageSectionsSeeder.php` (About, Contact, policies, FAQ — PR #49).

---

## 0. Shared pieces used on every page here

These are the patterns the lead should lift into `00-shared.md`. Each page below refers to them by name.

| Piece | Build | Source in Figma |
|---|---|---|
| **Chrome** | Desktop: `Header` (`10:289`, Desktop 1440 × 210 as on `34:6107`) + `Footer — Vivid · বাংলা` Desktop (`41:9166`). Mobile: `Header` Mobile 390 × 161 (as `34:6314`) + Footer Mobile + `Bottom Tab Bar` (`10:290`). These pages keep the site header on mobile (no `MobileTopBar`; that is cart/product/checkout only). | Category row |
| **Breadcrumb** | Horizontal auto-layout, gap `space/8`. Links `SJ Vivid/Body/S` `text/secondary`; separator `Icon/CaretRight` 12 (10 on mobile) `icon/muted`; current item `SJ Vivid/Label/M` `text/primary`. | `34:6109` / `34:6316` |
| **Page header band** ("PH") | Full-width band, fill a pastel **base** tone (below), `Pattern / Confetti` (`39:252`) layer clipped to the band, exactly as the Category hero `34:6108`. Inner column 1200 at x 120 (358 at x 16 on mobile). Vertical auto-layout, padding top `space/24` (16 mobile), bottom `space/32` (20 mobile), gap `space/12`. Children: Breadcrumb (desktop only), optional Eyebrow (`7:80`), H1 `SJ Vivid/Heading/H1` (mobile: `SJ Vivid/Heading/H2`) `text/primary`, optional lead `SJ Vivid/Body/L` (mobile `Body/M`) `text/secondary`, max width 720. | Category hero |
| **PH tones** | `sky` → `pastel/sky` (default: Contact, Terms, FAQ, Search, Brands, Categories, Delivery) · `mint` → `pastel/mint` (Privacy) · `blush` → `pastel/blush` (Returns) · `butter` → `pastel/butter` (About). Eyebrow variant follows the tone (Sky / Mint / Coral / Butter). The code's Bloom gradients are dropped; the tone is still keyed by slug (the page document has no tone field). | — |
| **Card** | `bg/surface`, radius `radius/xl`, hairline `border/subtle`, `SJ/Elevation/e1`, padding 28 desktop / 20 mobile (as the checkout section cards). | `34:7035` |
| **Form field** | `Input` (`7:178`) Default · Focus · Error. Label `SJ Vivid/Label/M` `text/primary`, required star `text/sale` + sr-only word. Help line `SJ Vivid/Caption` `text/muted`; in Error the help line becomes the error text in the error colour **with** `Icon/WarningCircle` 14 before it (never colour alone). Selects are the Input with a trailing `Icon/CaretDown` (as the checkout district select `34:7052`). Height 52. | checkout `34:7031` |
| **Empty state** | Centred vertical stack, max 420 wide, gap `space/16`: a 160 circle `pastel/sky` holding `Icon/MagnifyingGlass` 80 `icon/brand` and an `Icon/Sparkle` 32 `brand/sun` at top right (decorative); title `SJ Vivid/Heading/H3`; body `SJ Vivid/Body/M` `text/secondary`; buttons row (Button Primary M + Button Ghost M; on mobile both L, full width, stacked); optional chip block 32 below. | new (replaces `EmptyState` 80:1589 Toy Mart art) |
| **Section rhythm** | Bands sit `space/32` apart on mobile, 48 on desktop (code `gap-8 lg:gap-12`). The page body ends 80 above the footer on desktop, 36 on mobile, as the home sections. | §10, §11 |

Placement on `34:5658`: the last existing row is Order confirmed (frames at y 23512, ending y ≈ 25305). This batch adds rows below it; the lead assigns each agent's starting y. Within my block, one row per page, **2200 px between row tops is not enough for tall frames — space rows by the tallest frame in the row + 300**. Label frame (`Label — NN · Page`, as `34:7363`) at row y − 150, x 0. Columns, matching the Category row: Desktop x 0 · Mobile x 1840 · extra states x 2340, 2840, 3340 … (500 apart). Frame numbers `NN` are suggestions (31–37); the lead renumbers after merging the five specs.

---

## 1. Search — `/search`, `/search?q=`

**Route:** `app/[locale]/search/page.tsx`. Results reuse the restyled ListingView (§16.3); only the header, the empty states and the suggestions dropdown are designed here. Files: `components/search/EmptyState.tsx`, `components/layout/{SearchBar,SearchField}.tsx`, `components/listing/ListingHero.tsx`.

### Frames
| Frame | Size | What |
|---|---|---|
| `31 · Search results — Desktop` | 1440 | Results header + 1 grid row (instance the Category `Body` rows, do not redraw) |
| `31 · Search results — Mobile` | 390 | Mobile head + filter bar + 1 grid row |
| `31 · Search no results — Desktop` / `— Mobile` | 1440 / 390 | Zero-hit layout (it changes the page: no filters, no grid) |
| `31 · Search empty query — Mobile` | 390 | `/search` with no `q` (mobile tab bar lands here). Desktop is the same column, not drawn |
| `31 · Search suggestions` | 600 × auto, three states side by side | The dropdown as a component set (§1.4) |

### 1.1 Results header (desktop)
The ListingHero **card** variant (search and brand pages put the hero inside the container, not full-bleed): a 1200 card at x 120, `pastel/sky`, radius `radius/2xl`, Confetti layer, padding 32/40. Vertical auto-layout, gap `space/12`:
1. Breadcrumb: হোম › সার্চের ফলাফল.
2. H1 `SJ Vivid/Heading/H1`: `“{query}” — যা পাওয়া গেল`. The query is user text: set it in Fredoka when Latin (per-range, §7.3). Long query: wraps to two lines, never truncates.
3. Intro `SJ Vivid/Body/L` `text/secondary`: `search.intro` with `{count}`.
4. Related-search pills (API `related[]`): the Category hero's sub-category pills (`34:6133`): selected "সব ফলাফল" dark pill (`bg/inverse`, `text/inverse`, count chip), others `bg/surface` pills with the facet count chip. Hidden when the API sends no related searches.
No collage on search (code passes none).

### 1.2 Results header (mobile)
No hero card. Under the header: the filter bar (as `34:6333`), then the one-line head `mobileHead`: `{count}টি ফলাফল` in `SJ Vivid/Heading/H3` + `“{query}”-এর জন্য` in `SJ Vivid/Body/S` `text/secondary`, baseline aligned, gap 6, padding 16. The H1 exists but is visually hidden (sr-only) — annotate it on the frame.

### 1.3 States
- **Zero hits (no filters)** — own frames. Desktop: Breadcrumb (desktop only) and H1 as §1.1 but **outside** a card (plain on `bg/canvas`, x 120, y +20 below the header). Then the Empty state: title `“{query}” দিয়ে কোনো খেলনা পাওয়া গেল না`, body `search.zeroBody`, primary "সার্চ মুছুন" (→ `/search`), ghost "সব খেলনা দেখুন" (→ `/toys`). Chip block: heading `SJ Vivid/Label/L` — **"আপনি কি এটা খুঁজছেন"** with up to 4 `Chip` (`7:146` Default) from the API's spelling suggestions, **or**, when there are none, "জনপ্রিয় সার্চ" with the four i18n chips. Draw the "Did you mean" variant on desktop and the "Popular searches" variant on mobile so both are on the canvas. Below: the **"অভিভাবকেরা এগুলো কিনছেন"** rail (API best sellers) — `Section Header` (`9:213`) without eyebrow, with "সব দেখুন", + 4 `Product Card` desktop / 2-up scroller mobile, as the cart cross-sell rail `34:6914`. Hidden when the API sends no best sellers. Mobile: H1 is sr-only; the empty state starts 8 under the header.
- **Zero with filters** — inside the grid area (sidebar stays): the Empty state without chips, title "এই ফিল্টারে কোনো খেলনা নেই", body `filteredZeroBody`, primary "সব ফিল্টার মুছুন", ghost "বয়স অনুযায়ী কিনুন" (→ home `#shop-by-age`). Not its own frame: annotate it as a variant beside the desktop results frame (x 2340, 888 wide, the Results column only).
- **Empty query** — PH band (sky) with H1 "খুঁজুন", lead `search.emptyQuery`, and the `Search` component Mobile 358 (Desktop 560) inside the band.
- **Loading** — none (server-rendered). **No JS** — identical; the dropdown simply never appears.
- **Bangla long text** — the query in the title can be long Bangla: H1 wraps; check 2 lines at 390.

### 1.4 Search suggestions dropdown (`components/layout/SearchField.tsx`)
Component set `Search Suggestions` with `State` = `Results` · `Recent` · `No match`; width = the search pill + 48 (desktop ≈ 600, mobile 358). Panel: `bg/surface`, radius `radius/lg`, 1 px `border/subtle`, `SJ/Elevation/e3`, padding 8 vertical, max height 520, 8 below the pill. Draw a desktop placement too: the `Results` instance open under the header search on a cropped header (`31 · Search suggestions — in header`, 1440 × 760).
- **Group header** (28 tall, padding 16 horizontal): label `SJ Vivid/Overline` `text/muted` ("খেলনা" / "ক্যাটাগরি" / "ব্র্যান্ড") and the count `SJ Vivid/Caption` `text/muted` right-aligned. Hairline `border/subtle` between groups.
- **Product row** (56): 40 thumbnail radius `radius/xs` on `pastel/blush` (API `image`), name `SJ Vivid/Body/M` `text/primary` 1 line + ellipsis (English names in Fredoka), age line `SJ Vivid/Caption` `text/muted` (API age months → `listing.age*` templates), price `SJ Vivid/Price/M` `text/primary`, Fredoka digits (the API's selling price, not a sale price; the code uses the discount colour, see Deviations).
- **Category row** (44): category icon 20 `icon/brand`, name, count `Caption` `text/muted`. **Brand row** (44): `Icon/Storefront` 20 `icon/brand`, name, count.
- **See all row** (48, centred, hairline above): `“{query}”-এর সব {count}টি ফলাফল দেখুন` `SJ Vivid/Label/M` `text/brand` + `Icon/ArrowRight` 16.
- **Active row** (keyboard ↑/↓): fill `pastel/sky`. Hover: `bg/subtle`. Draw the second product row active.
- **Recent**: header "আগে যা খুঁজেছেন" + "সব মুছুন" (`Label/S` `text/brand`, 44 hit area), then wrapped `Chip` Default with a trailing ✕ (28, 44 hit area) for each stored query; active chip = `Chip` Selected.
- **No match**: 72-tall centred stack: `Icon/MagnifyingGlass` 20 `icon/muted` + `search.suggestNoMatch` `Caption` `text/secondary`.

---

## 2. Brands — `/brands` and the `/brands/[slug]` header

**Routes:** `app/[locale]/brands/page.tsx`, `brands/[slug]/page.tsx` (ListingView). API `StorefrontBrand`: `name`, `name_bn`, `slug`, `description`, `description_bn`, `logo_url`. There is **no product count** and no brand image other than the logo.

### Frames
`32 · Brands — Desktop` / `— Mobile`; `32 · Brand page header — Desktop` (1440 × header + first grid row) / `— Mobile`; `32 · Brand empty — Desktop` (Results column variant, x 2340).

### 2.1 Brands index
- PH band, tone sky: breadcrumb হোম › ব্র্যান্ড, H1 "ব্র্যান্ড". No lead (no string exists; see Open).
- **Grid**: desktop 4 columns × 282, gap `space/24`; mobile 2 columns × 171, gap `space/16`. Each **Brand card** (new component, `Size` Desktop/Mobile): Card, padding 20 (16 mobile), vertical, gap `space/12`, the whole card is the link.
  - Logo tile: 88 squircle (`radius/lg`), `bg/surface` with `border/subtle` holding `logo_url` contained, 64 max. Without a logo: the tile takes a pastel `-strong` tone picked by slug (butter, sky, lilac, mint, blush; code `toneFor`), with the brand's first letter in Fredoka 36/700 in that tone's `-ink`.
  - Name `SJ Vivid/Heading/H4` `text/primary` (brand names stay English, Fredoka; a Bangla `name_bn` uses Anek Bangla).
  - Description, 2 lines clamped, `SJ Vivid/Body/S` `text/secondary`, only when the API sends one.
  - Footer row: "দেখুন" `Label/M` `text/brand` + `Icon/ArrowRight` 16.
  - Hover (code, pointer only): shadow e1 → e2. Focus: `SJ/Focus/ring` on the card.
- **Empty** (no brands): Empty state without the magnifier — use `Icon/Storefront` — title `brands.empty`, primary "সব খেলনা দেখুন" (→ `/toys`; reuses `search.browseAll`). Annotate, no own frame.

### 2.2 Brand page header
The ListingHero card variant (as §1.1) with: breadcrumb হোম › ব্র্যান্ড › {name}; a **logo tile** 96 (`bg/surface`, `radius/lg`, e1) left of the copy, gap `space/24` (mobile: 64 tile above the title); H1 = brand name; intro = `description` (hidden when null); sub-category pills from the category facet ("সব" + each category with count). Mobile: tile, H1 `Heading/H2`, intro `Body/M`, pills in a horizontal scroller (as `34:6322`).
**Empty brand** (no products, no filters): the ListingView empty card restyled — Card, `Icon/PuzzlePiece` 48 `icon/brand`, title "এই ভাগের খেলনা শিগগিরই আসছে।" `Heading/H3`, Button Primary M "সব ব্র্যান্ড" (see Deviations — the code's label says "all categories" but links to `/brands`).

---

## 3. All categories — `/categories`

**Route:** `app/[locale]/categories/page.tsx` (`CategoryLinks`, `content/MobileMenuLinks`). Data: the category tree (`id`, `name`, `name_bn`, `slug`, `icon`, `image_url`, `tagline`, `tagline_bn`, `children[]`) and, on mobile, the published CMS `mobile` menu. This is where the mobile header's menu button lands.

### Frames
`33 · All categories — Desktop` / `— Mobile` (mobile shows the CMS menu card first).

### Sections
1. PH band, tone sky: breadcrumb হোম › সব ক্যাটাগরি, H1 "সব ক্যাটাগরি".
2. **Mobile only — CMS menu** (when published): Card, padding 0, rows 52 tall with hairline `border/subtle` between. Level-1 link `SJ Vivid/Label/L` `text/primary` + `Icon/CaretRight` 16 `icon/muted`; a label-only row is `SJ Vivid/Overline` `text/muted`; level-2 links indented 12, `SJ Vivid/Body/M` `text/secondary`, 44 tall. Sample rows: অফার ও ডিল · নতুন এসেছে · বয়স অনুযায়ী (menu content is CMS data — label it sample).
3. **Category cards grid**: desktop 3 columns × 384, gap `space/24`; mobile 1 column 358, gap `space/16`. **Category card** (new, `Size` Desktop/Mobile): Card, horizontal header row (gap `space/16`): an 80 circle (64 mobile) in a `SJ · Category tint` mode (cycle Butter, Lilac, Mint, Blush, Coral, Sky like the home row) holding `image_url` (or the category icon 32 in `tint/ink` without an image); beside it the name `SJ Vivid/Heading/H3` (link) and the `tagline` `SJ Vivid/Body/S` `text/secondary`. Below, the children as wrapped `Chip` Default links (min 44 hit area), gap `space/8`. A card with no children ends after the header row. No product counts (the tree sends none).
4. **Empty**: `categories.empty` as a Body/M line in a Card. Annotate only.

---

## 4. CMS static pages — `/[slug]` (Privacy, Terms, Delivery policy, Returns, FAQ, any new page)

**Rule 34a:** every page is sections. The storefront picks one of two frames by content (`lib/content/page-toc.ts`): **Stacked** (fewer than two contents entries — sections full width, the homepage block components) or **Article** (two or more h2s across rich-text sections, or two or more FAQ sections — a sticky contents list beside a 900 column). Files: `components/content/{CmsSectionsPage,CmsPageView}.tsx`, `blocks/{RichTextBlock,FaqBlock,HelpHeroBlock,ImageBlock,ChatBandBlock}.tsx`, `components/faq/*`. `components/StaticPage.tsx` is the built-in fallback before the CMS page exists — same frame.

### Frames
| Frame | Content (seeded) |
|---|---|
| `34 · Policy page (Article) — Desktop` / `— Mobile` | Privacy: header with "last updated" pill, contents list, numbered rich text |
| `34 · FAQ page — Desktop` / `— Mobile` | Help hero, topic list, 4 FAQ groups, chat band |
| `34 · Stacked page — Desktop` | Delivery policy: header, rich text (one h2 only), FAQ group, "more pages" |
| `34 · FAQ search — no match` | 1440 crop of the FAQ column (state) |

### 4.1 Page header (Article and Stacked)
A **card** inside the 1200 column (not full bleed): PH tone fill per slug, radius `radius/2xl` (`radius/xl` mobile), Confetti layer, padding 48/72 desktop, 24 mobile, gap `space/16`. Optional "last updated" pill (Article pages with numbered sections): `bg/surface`, `radius/full`, padding 6/14, `SJ Vivid/Overline` `text/brand`: `সর্বশেষ হালনাগাদ {date}` (Western digits, Bangla month). H1 `SJ Vivid/Display/L` desktop, `Heading/H1` mobile. English-only note (`content.englishOnly`, `Body/S` `text/secondary`) when a Bangla page falls back to English — draw it on the mobile Stacked frame.

### 4.2 Article layout (desktop)
Horizontal, gap 48, 40 under the header. **Contents card** 300 wide, sticky: Card with `border/subtle`, padding 24/16; label "এই পাতায়" `Overline` `text/muted`; rows 46 tall, padding 10/12, radius `radius/sm`: number `01` `SJ Vivid/Body/S` `text/secondary` (Fredoka digits) + label `Body/M` `text/primary`; hover/current row `pastel/sky` + `text/brand`. Under it on desktop: **More pages card** (same card; label "Smart Junior সম্পর্কে আরও"; links `Body/S` `text/brand`, 44 rows). **Column** 852 (1200 − 300 − 48).
Mobile: the contents list folds into a disclosure row: Card 358 × 52, "যে অংশে যেতে চান" `Body/M` + `Icon/CaretDown`; draw it closed, plus an open copy at x 2340. More pages card moves to the end of the page.

### 4.3 Rich text block (the generic text look)
- **Plain** (Stacked pages, max 720 measure, centred): paragraphs `SJ Vivid/Body/L` `text/primary` (mobile `Body/M`), 20 apart; h2 `SJ Vivid/Heading/H2`; h3 `SJ Vivid/Heading/H4`; bullets: 7 px dots `brand/lagoon`, item text indented 18, items 14 apart; numbered lists Western digits; links `text/brand` underlined; blockquote 4 px start rule `border/brand` + `text/secondary`; inline image radius `radius/lg`.
- **Numbered** (Article pages): the text split at each h2 into sections separated by `border/subtle` hairlines, 40 vertical padding (28 mobile). Section head: a 40 disc (32 mobile) cycling `pastel/blush-strong`, `pastel/sky-strong`, `pastel/mint-strong`, `pastel/lilac-strong`, `pastel/butter-strong`, `pastel/coral-strong` with the number `01` in Fredoka `Label/L` `text/primary`, gap 14, then the h2. Body indented 54 on desktop (aligned under the heading text), not indented on mobile. Muted text never sits on these discs (rule §11).

### 4.4 FAQ (help hero, topics, groups)
- **Help hero** (`help_hero`, FAQ page header): the 4.1 card, centred on desktop (left on mobile): Eyebrow Sky "সহায়তা কেন্দ্র", H1 "সাধারণ প্রশ্ন", lead, and the `Search` component (Desktop 560 / Mobile 358) **without** the orange button, placeholder from the block ("প্রশ্ন খুঁজুন, যেমন “ডেলিভারি চার্জ”"). It filters the page in place.
- **Topic list** (replaces the contents card when every entry is an FAQ group): Card 300; rows: 34 disc `bg/subtle` with the group icon 18 `icon/default`, title `Body/M`, question count `Body/S` `text/secondary`. Current row: `pastel/sky` fill, disc `bg/brand-strong` with `icon/inverse`, title `Label/M` `text/brand`. Mobile: a horizontal scroller of `Chip` (Selected = current), bleeding off the right edge.
- **FAQ group**: h2 group title `Heading/H2` then items 12 apart. **FAQ item** (new component, `State` Closed/Open): `bg/surface`, `border/subtle`, `radius/lg`; summary row min 56, padding 16, question `SJ Vivid/Label/L` `text/primary` + `Icon/CaretDown` 20 (rotates 180 when open); answer `SJ Vivid/Body/M` `text/primary`, padding 0/16/16. On the FAQ page each group's first item is Open. Focus ring on the summary row.
- **Search states**: results count line under the search (`{count}টি মেলানো প্রশ্ন`, `Body/S`); **no match** frame: the column replaced by a Card with `faq.noResults` and a WhatsApp ghost button (only when WhatsApp is set).
- **Chat band** ("আরও সাহায্য দরকার?"): the existing Help CTA card (`34:5872`) with the WhatsApp button; in the 852 column on Article pages.

### 4.5 Other blocks that appear on static pages
`image` (inside the measure or 1200 wide, radius `radius/lg`, caption `Caption` `text/muted`), `contact_info`, `promo_split` and any home block use their home look — not redrawn here. Draw one `image` with caption on the Stacked frame.

---

## 5. About — `/about`

**Route:** `app/[locale]/about/page.tsx`. Seeded sections (PR #49): `story_hero` → `feature_tiles` → `numbered_cards` → `cta_band`. The story hero is the page header (its title is the H1). Files: `blocks/{StoryHeroBlock,FeatureTilesBlock,NumberedCardsBlock,CtaBandBlock}.tsx`.

### Frames
`35 · About — Desktop` / `— Mobile`. Copy is CMS block data from the seeder (shown below); it is not i18n.

### Sections
1. **Story hero**: a card in the 1200 column, tone from the block (`sunrise` → `pastel/butter`, `sky` → `pastel/sky`, `mint` → `pastel/mint`, `candy` → `pastel/blush`), radius `radius/2xl`, Confetti, padding 64/64/64/72 (mobile 24/20/28). Desktop horizontal, gap 56: copy column (fill) — Eyebrow Butter "আমাদের গল্প", H1 `SJ Vivid/Display/L` "Smart Junior সম্পর্কে", lead `Body/L` `text/primary`, body `Body/L` `text/secondary`, gap 18 — and a 500 × 400 **sticker collage**: up to 3 photos in `bg/surface` frames (padding 8, `radius/lg`, `SJ/Elevation/e2`) rotated −6°, 5°, −3°, plus a 64 round badge `brand/sun` rotated 10° with "বয়স" / "0–12" in `Label/S` `text/primary`. Mobile: collage on top (full width, 5:4), copy below. Photos are the seeded placeholders; the badge copy is CMS data.
2. **Feature tiles** ("Our promises", 4 tiles, no section title): desktop 4 × 282, gap 20; mobile 2 × 171, gap 12. Tile: tone by position `pastel/mint`, `pastel/sky`, `pastel/butter`, `pastel/lilac` (base tints — muted text is allowed on base, not on -strong), `radius/lg`, padding 24 (16). A 48 disc (40 mobile) in `bg/surface` with the icon 24 in the tone's `-ink` (white icons on green/yellow fail 3:1, §15). Title `SJ Vivid/Heading/H4` (h2 here, since there is no section title), body `Body/M` `text/secondary`.
3. **Numbered cards** ("আমরা যা বিশ্বাস করি"): `Section Header` (`9:213`) with eyebrow and see-all hidden, heading only. 3 cards × 384, gap 24 (mobile stacked, gap 12). Card: the §0 Card, padding 28 (20); numeral `01` Fredoka 52/700 cycling `text/brand`, `text/success`, `pastel/lilac-ink`; title `Heading/H4`; body `Body/M` `text/secondary`. Mobile: numeral (36) beside the copy.
4. **CTA band** ("আমাদের দোকানে খেলতে আসুন"): the Help CTA card (`34:5872` / mobile `34:6100`), tone `candy` → `pastel/blush`, Confetti, outline "যোগাযোগ করুন" + primary "পথ দেখুন" with icons. **The address and hours in its body are placeholders** — set the text layer to "বাসা 12, রোড 7, ধানমন্ডি, ঢাকা · শনি থেকে বৃহস্পতি, সকাল 10টা – রাত 8টা" and mark it `placeholder` (red dashed annotation).

States: none beyond the Bangla long text (story hero title wraps to 2 lines at 390). If the owner has not published About from the builder, the built-in fallback (`StaticPage` About) renders — same frame.

---

## 6. Contact — `/contact` (CMS page) and `/contact-message`

**Routes:** `/contact` is a CMS page (`[slug]`, Stacked layout). Seeded: `contact_hero` → `contact_info` → `contact_form`, then the chat buttons (`ChatLinks`) when WhatsApp/Messenger are set. `/contact-message` (`app/[locale]/contact-message/page.tsx`) is where the no-JS POST lands: the thank-you, or the form again with errors. The form posts to `/api/contact` → admin Inbox. Files: `blocks/{ContactHeroBlock,ContactInfoBlock,ContactFormBlock}.tsx`, `components/contact/MessageField`.

### Frames
`36 · Contact — Desktop` / `— Mobile`; `36 · Contact message sent — Desktop` / `— Mobile`; `36 · Contact form errors — Mobile` (the 390 form with three field errors and the top banner).

### 6.1 Contact hero (page header, H1)
Card, `pastel/sky`, `radius/2xl`, Confetti, padding 56/64/56/72. Copy column: Eyebrow Sky "আমরা সাহায্য করতে আছি", H1 `Display/L` "যোগাযোগ করুন", lead `Body/L` `text/secondary`, and a **status pill** (`bg/surface`, `radius/full`, 8/16): a 10 dot `brand/learn` (decorative) + `Body/S` `text/primary` "এখন অনলাইনে · হোয়াটসঅ্যাপে প্রায় 15 মিনিটে উত্তর". Desktop right column 420: chat bubbles, 14 apart, decorative — customer bubble `bg/surface` `text/primary` (tail bottom-left `radius/xs`), store bubble `bg/brand-strong` `text/inverse` (tail bottom-right), each with an `Overline` label ("অভিভাবক" / "Smart Junior"). Mobile: no bubbles.
**Placeholders:** the lead's hours ("সকাল 9টা থেকে রাত 9টা") and the status line's reply time are unconfirmed — mark both `placeholder`.

### 6.2 Contact channels (`contact_info`)
Section title hidden (sr-only "যোগাযোগের উপায়"). Tiles in Figma order WhatsApp · Call · Email · Visit (Messenger after Email when set): desktop 4 × 285, gap 20; mobile 2 × 171, gap 12. Tile: base pastel (WhatsApp `pastel/mint`, Call `pastel/sky`, Email `pastel/lilac`, Visit `pastel/butter`, Messenger `pastel/blush`, Hours-only `pastel/coral`), `radius/lg`, padding 24 (16). 48 disc `bg/surface` with the channel icon in the tone's `-ink`; title `Heading/H4` ("হোয়াটসঅ্যাপ", "ফোন করুন", "ইমেইল", "দোকানে আসুন"); value `Label/L` `text/primary` (phone and email in Fredoka); caption `Body/S` `text/secondary` (desktop only); action link `Label/M` `text/primary` underlined + `Icon/ArrowRight` ("চ্যাট শুরু করুন", "এখনই ফোন করুন", "ইমেইল লিখুন", "পথ দেখুন"), 44 tall.
**Every value is a placeholder until the owner sets Settings › Store › Business:** hotline `09612-345678`, WhatsApp, email, "ধানমন্ডি, ঢাকা", the hours caption. In code a sample value renders **without** its action link — draw the desktop frame with links (owner-set look) and the mobile frame with the sample look (no links, values marked `placeholder`), so both cases exist.

### 6.3 Contact form (`contact_form`)
Desktop horizontal, gap 32: **form card** (fill, ≈ 728) + **side rail** 440 (Visit card, Links card, 24 apart). Mobile: stacked, rail after the form.
- Form card (§0 Card, gap 20): H2 `Heading/H2` "আমাদের মেসেজ পাঠান" (H1 on `/contact-message`); intro `Body/M` `text/secondary`; 2 × 2 grid (gap 16 × 20; 1 column mobile) of §0 Form fields: **আপনার নাম*** (placeholder "যেমন নুসরাত জাহান"), **মোবাইল নম্বর*** (placeholder `01XXXXXXXXX`, Fredoka), **কী বিষয়ে?** (select; default "খেলনা বাছাইয়ে সাহায্য"), **অর্ডার নম্বর** (placeholder `TMB-`); then **আপনার মেসেজ*** textarea 120 tall with the counter `0 / 500` right-aligned in the help row (`Caption` `text/muted`, Fredoka digits); then Button Primary L "মেসেজ পাঠান" (full width on mobile) beside the privacy line `Body/S` `text/secondary`.
- **Visit card**: a 180 map tile `pastel/sky`, `radius/lg`, centring a 56 disc `bg/brand-strong` with `Icon/MapPin` `icon/inverse` (a link when the block has a map URL); title `Heading/H4` "আমাদের দোকানে আসুন"; body; hours as label/value rows (`Body/M` `text/secondary` / `Label/M` `text/primary`). **Address, hours and map are placeholders** — no real map image is drawn; the tile stays an illustration.
- **Links card**: title `Heading/H4` "মেসেজের চেয়েও দ্রুত"; rows 56 with hairline above: 36 disc `pastel/sky` + icon 18 `pastel/sky-ink`, label `Label/L`, `Icon/CaretRight`. Rows: অর্ডার ট্র্যাক করুন · ডেলিভারি চার্জ ও সময় · রিটার্ন ও টাকা ফেরত · সাধারণ প্রশ্ন.
- **Chat buttons** (after the sections, only when set): `chat.title` `Heading/H3` + Button Outline M with WhatsApp / Messenger icons.

### 6.4 States
- **Errors** (`/contact-message` after a failed post, own mobile frame): the form card with H1; a top banner when the post failed or was rate-limited — `pastel/butter`, `radius/md`, padding 12/16, `Icon/WarningCircle` 20 `text/on-butter` + `Body/S` `text/primary` (`contactFormFailed`). Fields Name, Phone and Message in `Input` Error with their error text (from `contactFormErrors`), typed values kept.
- **Sent** (own frames): PH-free page; a centred 640 Card inside a `pastel/mint` band with Confetti (the Order-confirmed content language, `34:7267`): 56 disc `pastel/mint-strong` with `Icon/CheckCircle` 28 `pastel/mint-ink`; H1 `Heading/H1` "ধন্যবাদ — আপনার মেসেজ আমরা পেয়েছি"; body `Body/L` `text/secondary` with the visitor's number (Fredoka); Button Secondary M "যোগাযোগ পাতায় ফিরুন". `role=status`.
- **No JS**: identical (plain POST, 303 to `/contact-message`). The live counter needs JS; without it the counter is absent — annotate.

---

## 7. Delivery charge — `/delivery-charge`

**Route:** `app/[locale]/delivery-charge/page.tsx` (`components/delivery/DeliveryEstimator*`). Data: `DeliveryRates` (`zones[]` with `charge`, `min_days`, `max_days`; `free_shipping_threshold`, `included_weight_grams`, `per_kg_charge`, `cutoff_time`, `courier`) and, with `?district_id=…`, a server-rendered `DeliveryQuote`. Every figure is the API's (rule 1).

### Frames
`37 · Delivery charge — Desktop` / `— Mobile`. The estimator's own states (Empty, Selected, Free, Not serviced, Loading, Error) belong to the product-page estimator and are **not** redrawn; place the Empty and Selected states only.

### Sections
1. PH band, tone sky: breadcrumb হোম › ডেলিভারি চার্জ, H1 "ডেলিভারি চার্জ", lead `delivery.pageIntro`.
2. **Body** — desktop horizontal, gap 32, inside the 1200 column (proposed; the code uses one 480 column, which leaves 960 empty at 1440): **Estimator card** 520 (§0 Card): title `Heading/H4` "ডেলিভারি চার্জ ও সময়", helper `Body/S` `text/secondary`, District select (§0 field, help "ডেলিভারি চার্জ এর উপর নির্ভর করে"), Upazila select (only when `upazila_required`), Button Primary M "দেখুন". The published line under it: `ঢাকায় ৳{metro} · ঢাকার বাইরে ৳{outside}` (`publishedNoFree` — draw this one; the free-over variant only appears when the API sends a threshold). Draw the **Selected** state on desktop: charge `SJ Vivid/Price/M`-scale 28 Fredoka `text/primary`, "ডেলিভারি চার্জ · {district}", arrival line, courier line.
   **Zone table card** (fill ≈ 648): Card padding 0, `radius/lg`, clipped. Header row 44 `bg/subtle`: এলাকা · চার্জ · ডেলিভারির সময় (`Label/M` `text/primary`; charge and time right-aligned). Rows 52 with `border/subtle` hairline: zone name `Body/M` `text/primary` (row header), charge `Label/L` Fredoka `text/primary`, time `Body/M` `text/secondary` (`{min}–{max} কর্মদিবস`). Under the table, two notes `Caption` `text/muted`: the weight note and the cut-off note.
   Mobile: estimator card, then the table card, full width 358.
3. **Values**: charges, days, the weight step and the cut-off time come from the API. Draw them with whatever the local API returns (`GET` the rates before building; do not type figures from memory) and mark every figure `sample` — the owner has not confirmed delivery charges (Known gaps, `UI-UX-DESIGN-SPEC.md` §10). No "free delivery" claim is drawn.

States: **no rates** (API down) — the table card is absent and the estimator shows its Error state ("চার্জ আনতে সমস্যা হয়েছে — প্রকাশিত হার দেখানো হচ্ছে"); annotate only. **No JS**: the estimator is a GET form that reloads this page with the quote — same look.

---

## 8. Copy table

Keys are the storefront's `lib/i18n` keys (they already exist; numerals Western). Block copy (About, Contact, policies, FAQ) is CMS data from `StaticPageSectionsSeeder` and is quoted in §4–6, not repeated here.

| Key | বাংলা | English |
|---|---|---|
| `search.label` / `submit` | খুঁজুন | Search |
| `search.placeholder` | খেলনা, ব্র্যান্ড বা বয়স খুঁজুন | Search toys, brands or age |
| `search.clear` | মুছুন | Clear |
| `search.pageTitle` | খুঁজুন | Search |
| `search.emptyQuery` | খুঁজতে খেলনা, ব্র্যান্ড বা বয়স লিখুন। | Type a toy, a brand or an age to search. |
| `search.crumb` | সার্চের ফলাফল | Search results |
| `search.resultsTitle` | “{query}” — যা পাওয়া গেল | Results for “{query}” |
| `search.intro` | নাম, ব্র্যান্ড আর ক্যাটাগরি মিলিয়ে {count}টি খেলনা পাওয়া গেছে। সম্পর্কিত সার্চ বা ফিল্টার দিয়ে আরও ছোট করুন। | {count} toys match your search across names, brands and categories. Narrow it down with a related search or the filters. |
| `search.countHead` / `countHeadOne` | {count}টি ফলাফল / 1টি ফলাফল | {count} results / 1 result |
| `search.forQuery` | “{query}”-এর জন্য | for “{query}” |
| `search.allResults` | সব ফলাফল | All results |
| `listing.relatedSearches` | সম্পর্কিত সার্চ | Related searches |
| `search.zeroTitle` | “{query}” দিয়ে কোনো খেলনা পাওয়া গেল না | No toys found for “{query}” |
| `search.zeroBody` | বানান দেখে নিন, অথবা “lego” বা “blocks”-এর মতো ছোট শব্দ দিয়ে খুঁজুন। | Check the spelling, or try a shorter word like “lego” or “blocks”. |
| `search.clearSearch` | সার্চ মুছুন | Clear search |
| `search.browseAll` | সব খেলনা দেখুন | Browse all toys |
| `search.didYouMean` | আপনি কি এটা খুঁজছেন | Did you mean |
| `search.popularSearches` | জনপ্রিয় সার্চ | Popular searches |
| `search.popular.*` | লেগো · রিমোট কন্ট্রোল গাড়ি · পাজল · পুতুল | LEGO · Remote control cars · Puzzles · Dolls |
| `search.filteredZeroTitle` | এই ফিল্টারে কোনো খেলনা নেই | No toys match those filters |
| `search.filteredZeroBody` | একটা ফিল্টার সরিয়ে দেখুন, অথবা বয়স অনুযায়ী দেখুন। | Try removing a filter, or browse by age instead. |
| `search.clearFilters` | সব ফিল্টার মুছুন | Clear all filters |
| `search.shopByAge` | বয়স অনুযায়ী কিনুন | Shop by age |
| `search.popularNow` | অভিভাবকেরা এগুলো কিনছেন | Popular right now |
| `search.seeAll` | সব দেখুন | See all |
| `search.suggestProducts` / `Categories` / `Brands` | খেলনা / ক্যাটাগরি / ব্র্যান্ড | Products / Categories / Brands |
| `search.suggestSeeAll` | “{query}”-এর সব {count}টি ফলাফল দেখুন | See all {count} results for “{query}” |
| `search.suggestNoMatch` | দ্রুত কিছু মেলেনি — পুরো সার্চের জন্য এন্টার চাপুন | No quick matches — press Enter for full search |
| `search.recentTitle` / `recentClear` | আগে যা খুঁজেছেন / সব মুছুন | Recent searches / Clear all |
| `search.recentRemove` | “{query}” মুছুন | Remove “{query}” |
| `brands.title` | ব্র্যান্ড | Brands |
| `brands.empty` | এখনো কোনো ব্র্যান্ড নেই। | No brands to show yet. |
| `brands.backToAll` | সব ব্র্যান্ড | All brands |
| `brands.logoAlt` | {name} লোগো | {name} logo |
| `brands.shop` **(new)** | দেখুন | Shop |
| `listing.all` | সব | All |
| `listing.emptyNoProducts` | এই ভাগের খেলনা শিগগিরই আসছে। | Toys for this section are on their way. |
| `categories.title` | সব ক্যাটাগরি | All categories |
| `categories.empty` | ক্যাটাগরিগুলো শিগগিরই এখানে দেখা যাবে। | Categories will appear here soon. |
| `content.menu` | মেনু | Menu |
| `content.onThisPage` | এই পাতায় | On this page |
| `content.jumpTo` | যে অংশে যেতে চান | Jump to a section |
| `content.updated` | সর্বশেষ হালনাগাদ {date} | Last updated {date} |
| `content.morePages` | Smart Junior সম্পর্কে আরও | More from Smart Junior |
| `content.englishOnly` | এই পাতাটি এখনো বাংলায় নেই, তাই ইংরেজিতে দেখানো হচ্ছে। | This page is not available in Bangla yet, so it is shown in English. |
| `faq.topics` | বিষয় | Topics |
| `faq.searchPlaceholder` | প্রশ্ন খুঁজুন | Search questions |
| `faq.results` / `resultsOne` | {count}টি মেলানো প্রশ্ন / 1টি মেলানো প্রশ্ন | {count} matching questions / 1 matching question |
| `faq.noResults` | “{query}”-এর সঙ্গে মেলে এমন প্রশ্ন নেই। অন্য শব্দ দিয়ে চেষ্টা করুন, বা হোয়াটসঅ্যাপে জিজ্ঞেস করুন। | No question matches “{query}”. Try another word, or ask us on WhatsApp. |
| `content.contactChannels` | যোগাযোগের উপায় | Ways to reach us |
| `content.contactWhatsappTitle` / `contactCallTitle` / `contactEmailTitle` / `contactAddress` / `contactMessenger` / `contactHours` | হোয়াটসঅ্যাপ / ফোন করুন / ইমেইল / দোকানে আসুন / মেসেঞ্জার / খোলার সময় | WhatsApp / Call us / Email / Visit the shop / Messenger / Opening hours |
| `content.contactChatCta` / `contactCallCta` / `contactEmailCta` / `contactMap` | চ্যাট শুরু করুন / এখনই ফোন করুন / ইমেইল লিখুন / পথ দেখুন | Start a chat / Call now / Write an email / Get directions |
| `content.contactFormName` (+Help, Placeholder) | আপনার নাম · যাতে আমরা জানি কাকে খুঁজব। · যেমন নুসরাত জাহান | Your name · So we know who to ask for. · e.g. Nusrat Jahan |
| `content.contactFormPhone` (+Help) | মোবাইল নম্বর · এই নম্বরে আমরা কল বা হোয়াটসঅ্যাপ করব। | Mobile number · We will call or WhatsApp you on this number. |
| `content.contactFormTopic` (+Help) | কী বিষয়ে? · অর্ডার, ডেলিভারি, রিটার্ন, উপহার বা পাইকারি অর্ডার | What is it about? · Orders, delivery, returns, gifts or bulk orders |
| `content.contactFormTopics.*` | খেলনা বাছাইয়ে সাহায্য · আমার দেওয়া একটি অর্ডার · ডেলিভারি · রিটার্ন ও টাকা ফেরত · উপহার বা পাইকারি অর্ডার · অন্য কিছু | Help choosing a toy · An order I placed · Delivery · Returns and refunds · Gifts or bulk orders · Something else |
| `content.contactFormOrder` (+Help) | অর্ডার নম্বর · ঐচ্ছিক। এটি আপনার এসএমএস ও ইনভয়েসে আছে। | Order number · Optional. It is on your SMS and invoice. |
| `content.contactFormMessage` (+Help, Placeholder) | আপনার মেসেজ · যত বেশি জানাবেন, তত ভালোভাবে সাহায্য করতে পারব। · আপনার সন্তানের বয়স আর সে কী ভালোবাসে, বা অর্ডারে কী সমস্যা হয়েছে, লিখুন। | Your message · The more you tell us, the better we can help. · Tell us your child’s age and what they love, or what went wrong with an order. |
| `content.contactFormCount` | {count} / {max} | {count} / {max} |
| `content.contactFormSend` / `contactFormRequired` | মেসেজ পাঠান / আবশ্যক | Send message / required |
| `content.contactFormErrors.name` | অনুগ্রহ করে আপনার নাম লিখুন। | Please tell us your name. |
| `content.contactFormErrors.phone` | বাংলাদেশি মোবাইল নম্বর দিন, যেমন 01712345678। | Enter a Bangladeshi mobile number, such as 01712345678. |
| `content.contactFormErrors.topic` | মেসেজটি কী বিষয়ে, বেছে নিন। | Choose what your message is about. |
| `content.contactFormErrors.order_number` | অর্ডার নম্বরটি দেখে নিন — এটি দেখতে TMB-26-00042-এর মতো। | Check the order number — it looks like TMB-26-00042. |
| `content.contactFormErrors.message` | অন্তত 10 অক্ষর লিখুন (সর্বোচ্চ 500)। | Please write at least 10 characters (up to 500). |
| `content.contactFormFailed` | এই মুহূর্তে মেসেজটি পাঠানো গেল না। আবার চেষ্টা করুন, অথবা কল বা হোয়াটসঅ্যাপ করুন। | We could not send your message just now. Please try again, or call or WhatsApp us. |
| `content.contactFormLimited` | অল্প সময়ে কয়েকটি মেসেজ পাঠিয়েছেন। একটু অপেক্ষা করুন, অথবা কল বা হোয়াটসঅ্যাপ করুন। | You have sent several messages in a short time. Please wait a little, or call or WhatsApp us. |
| `content.contactSentTitle` | ধন্যবাদ — আপনার মেসেজ আমরা পেয়েছি | Thank you — your message is with us |
| `content.contactSentBody` | দোকান খোলা থাকলে সাধারণত কয়েক ঘণ্টার মধ্যে আমাদের কেউ {phone} নম্বরে কল বা হোয়াটসঅ্যাপ করবেন। | Someone from our shop will call or WhatsApp you on {phone}, usually within a few hours while we are open. |
| `content.contactSentBack` | যোগাযোগ পাতায় ফিরুন | Back to Contact us |
| `chat.title` / `whatsapp` / `messenger` | প্রশ্ন আছে? আমাদের সাথে চ্যাট করুন / হোয়াটসঅ্যাপে কথা বলুন / মেসেঞ্জারে কথা বলুন | Questions? Chat with us / Chat on WhatsApp / Chat on Messenger |
| `delivery.pageTitle` | ডেলিভারি চার্জ | Delivery charge |
| `delivery.pageIntro` | পুরো সাইটের জন্য একটাই চার্জের তালিকা — পণ্যের পাতায়, কার্টে আর চেকআউটে একই অঙ্ক। | One charge table for the whole site — the same figure you see on a product page, in the cart and at checkout. |
| `delivery.title` / `helper` | ডেলিভারি চার্জ ও সময় / জেলা বেছে নিন — কার্টে যাওয়ার আগেই সঠিক চার্জ আর সময় দেখুন। | Delivery charge & time / Pick your district — see the exact charge and time before you reach the cart. |
| `delivery.districtLabel` / `districtPlaceholder` / `districtHelp` | জেলা / আপনার জেলা নির্বাচন করুন / ডেলিভারি চার্জ এর উপর নির্ভর করে | District / Choose a district / Delivery charge depends on this |
| `delivery.upazilaLabel` / `upazilaPlaceholder` | উপজেলা বা এলাকা / উপজেলা নির্বাচন করুন | Upazila or area / Choose an upazila |
| `delivery.check` | দেখুন | Check |
| `delivery.publishedNoFree` | ঢাকায় {metro} · ঢাকার বাইরে {outside} | {metro} in Dhaka · {outside} outside |
| `delivery.zoneHeading` / `chargeHeading` / `timeHeading` | এলাকা / চার্জ / ডেলিভারির সময় | Zone / Charge / Delivery time |
| `delivery.zones.*` | ঢাকা শহর · ঢাকার আশপাশ · ঢাকার বাইরে | Dhaka city · Dhaka suburbs · Outside Dhaka |
| `delivery.zoneDays` | {min}–{max} কর্মদিবস | {min}–{max} working days |
| `delivery.weightNote` | {grams} গ্রামের বেশি ওজনের পার্সেলে প্রতি বাড়তি কেজিতে {charge} যোগ হয়। | Parcels over {grams} g add {charge} for each extra kg. |
| `delivery.cutoffNote` | {time}-এর আগে অর্ডার করলে সেদিনই পাঠানো হয়। শুক্রবার ও সরকারি ছুটির দিন বাদ যায়। | Orders placed before {time} leave the same working day. Fridays and public holidays are skipped. |
| `delivery.errorTitle` | চার্জ আনতে সমস্যা হয়েছে — প্রকাশিত হার দেখানো হচ্ছে | Couldn't fetch the live rate — showing our published rate |

---

## 9. Not designed — needs API

- **Brand product count** and a brand banner/hero image: `StorefrontBrand` sends neither. The brand card shows no count.
- **Category product counts** on `/categories`: the tree sends none.
- **Search result facts** (average rating, "dispatched in 24 h"): no data, as on the category page (§16.3).
- **Page tone** for CMS static pages: no field; the tone is keyed by slug in code. A `tone` on the page would let the owner pick it.
- **Brand lead line** on `/brands`: no string or setting exists.

## 10. Token gaps

1. **Error colour name.** `Input` has an Error variant, but no `text/danger` / `border/danger` appeared in the Vivid variable reads. The builder confirms the token the Input Error variant binds and uses it for error text; if it binds a primitive, that is a defect (rule 26).
2. **Warning fill.** No `bg/warning`-type token in `SJ · Color`; the contact banner uses `pastel/butter` + `text/on-butter` instead. Name one if the owner wants a distinct warning look.
3. **`SJ/Elevation/e3`** for the suggestions dropdown — confirm it exists (§1.3 lists `e1…`).
4. **Delivery and contact pages need no new colours.** The storefront-side gaps from §16.3 still apply (`border/subtle` has no storefront token; radius 36 renders 32).

## 11. Deviations and open questions for the owner

1. **Placeholders (not confirmed):** hotline, WhatsApp, email, shop address, opening hours, the "online now · 15 minutes" status, the map, the About visit band, and every delivery charge/day/cut-off figure. All are drawn as marked samples. The owner must confirm them in Settings › Store › Business, Pages › Contact/About and the delivery settings.
2. **Delivery page width:** the code uses one 480 column; this spec proposes estimator and table side by side at 1440. Accept, or keep the narrow column?
3. **Page header pattern:** the confetti appears on every page header here (it is the category-hero language). §7.4 limits the pattern to "specific sections"; confirm headers count, or draw policy headers without it.
4. **Brand empty button:** the code says "সব ক্যাটাগরি দেখুন" (Browse all categories) but links to `/brands`. The spec uses "সব ব্র্যান্ড"; code should follow.
5. **Suggestion price colour:** the code shows the dropdown price in the discount pink although it is not a sale price; the spec uses `text/primary`.
6. **Bangla brand names in chips:** the popular-search chip "লেগো" is a brand in Bangla, against §7.3 (brands stay English). Keep "LEGO" in both locales?
7. **Brand logos:** `logo_url` exists but the current brands index does not show it; the spec adds it (with a monogram fallback).
8. **Legacy templates:** pages never published from the builder still render `CmsPageView` (default / sidebar / FAQ). They take the same look (§4.1–4.4); no separate frames are drawn, as rule 34a retires them.
9. **About and Contact block copy** is the seeder's sample text; product names aside, it reads as fact ("7-day returns", "24 hours in Dhaka"). These are business claims the owner should confirm.
