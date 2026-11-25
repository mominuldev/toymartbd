# 04 · Blog and guides — Smart Junior Vivid spec

**Status:** spec for the Figma build, written 2026-11-25 by spec agent 4 (batch brief `docs/_handoff/briefs/brief-p10-sj-inner-pages.md`). Nothing is drawn yet.
**Target:** page `Smart Junior — Vivid · বাংলা` (`34:5658`) in file `2b6PVOOapPbcaKYIdHWZup`. Every frame sets `SJ · Color` to **Vivid**.
**Sources read:** storefront `app/[locale]/blog/**`, `app/[locale]/guides/**`, `components/blog/*`, `lib/blog/*`; OpenAPI schemas `StorefrontBlogPostCard`, `StorefrontBlogPost`, `StorefrontBlogTerm` (`docs/api/openapi.json`); Figma Journal `34:5851`, Category hero `34:6108`, product Breadcrumb `34:6396`, Pagination `34:6292`, "You may also like" `34:6669` / `34:6762`.

**Text styles.** The `SJ Vivid/*` styles mirror the `SJ/*` role names (§1.1 / §7.2 of `07-smart-junior-storefront.md`). This spec names them by role. The sizes below are the ones measured on the Vivid frames, so the builder should confirm each style name against the file:
`Display/L` 52 Hind Siliguri · `Heading/H1` 40 · `H2` 32 · `H3` 24 · `H4` 20 Anek SemiBold (the Journal card title) · `Body/L` 16 · `Body/M` 14 · `Body/S` 13 · `Label/M` 14/600 · `Label/S` 12/600 · `Caption` 12 · `Overline` 12/800 (not uppercased in Bangla). Latin words and digits inside a string are Fredoka, set per character range (§7.2).

---

## 1. Routes and purpose

| Route | Purpose | Rendered by |
|---|---|---|
| `blog` | Every live post and guide, newest first, 12 a page, with category chips | `app/[locale]/blog/page.tsx` → `pages.tsx renderList` → `BlogList`, `BlogCard`, `BlogPagination`, `Breadcrumbs` |
| `blog/[slug]` | One article (`type: article`). A guide slug under `/blog` gets a 308 to `/guides/…` | `blog/[slug]/page.tsx` → `renderPost` → `BlogArticle` |
| `blog/category/[slug]` | The category archive, with chips (the current one selected). It is a 404 when `posts_count` is 0 | `termSpec('category')` → `BlogList` |
| `blog/tag/[slug]` | The tag archive, with no chips | `termSpec('tag')` → `BlogList` |
| `blog/author/[slug]` | The author archive, with no chips | `termSpec('author')` → `BlogList` |
| `guides` | Only `buying_guide` posts, with no chips | `guides/page.tsx` → `renderList` |
| `guides/[slug]` | A buying guide: sections with age and budget chips and live product picks | `guides/[slug]/page.tsx` → `BlogArticle` (guide branch) |

The `blog/preview/[token]` route is out of scope. It is the private editor preview: the article page plus a status banner.

## 2. Frames to build

Use the next free rows below the **Order confirmed** row (`34:7265`, which ends at about y 25305). The rows go in agent order, so this spec is the 4th row (`Label — 10 · Blog & guides` if the lead numbers the batch 07–11). Follow the existing pattern: the label frame sits 150 above the row, each row starts 400 below the tallest frame of the row above, desktop frames are at x 0 and mobile frames at x 1840. This spec needs **two sub-rows**. Before placing anything, compute the bounding boxes and assert that no frames intersect (Figma rule 3).

| Sub-row | Frame name | Size | x |
|---|---|---|---|
| A | `10 · Blog — Desktop` (index, page 1, 6 cards shown) | 1440 × ~2700 | 0 |
| A | `10 · Blog — Mobile` | 390 × ~3300 | 1840 |
| A | `10 · Blog — Empty — Mobile` (state) | 390 × ~1500 | 2340 |
| A | `10 · Blog author — Desktop` (archive header + 3 cards; covers category and tag too, §3.3) | 1440 × ~1900 | 2840 |
| B | `10 · Blog article — Desktop` | 1440 × ~4600 | 0 |
| B | `10 · Blog article — Mobile` | 390 × ~5200 | 1840 |
| B | `10 · Buying guide — Mobile` | 390 × ~5600 | 2340 |
| B | `10 · Buying guide — Desktop` | 1440 × ~5000 | 2840 |

No tablet frames: the layout steps (3 → 2 → 1 columns) follow the Journal grid and need no frame of their own. The `guides` index reuses the Blog index frames (see §3.4), with no frame of its own.

Shared chrome on every frame. Use instances and do not redraw:
- **Desktop:** `Header` (set `10:289`, Desktop; reference instance `34:6107`) and `Footer — Vivid · বাংলা` Desktop (`41:8217`; instance `41:10524`).
- **Mobile:** `Header` Mobile (reference `34:6314`), `Footer` Mobile (`41:8832`), and `Bottom Tab Bar` (`10:290`) pinned to the bottom, as in `34:6358`. The blog pages use the full site header on mobile, **not** the MobileTopBar (code: `PageShell top="crumb"`).
- **Page background:** `bg/canvas`. Desktop content is 1200 wide with a 120 side margin; mobile has a 16 gutter.

## 3. Sections, top to bottom

### 3.1 List page hero: index, guides and archives (from the Category hero `34:6108`)

This is the same section as the Category hero, with the collage and fact pills removed.
- **Band:** full-bleed frame, vertical auto-layout. Fill `pastel/sky`, with the `Pattern / Confetti` (`39:252`) tiles behind it, as in `34:6108` (the Category hero is a Vivid section that already carries the pattern). Padding: top `space/28` (28), bottom 36, sides 120. Gap `space/20`. Mobile padding: 16 at the sides, 20 at the top, 24 at the bottom. Gap 12.
- **Breadcrumb:** copy `34:6109`. Horizontal, gap `space/8`, `Label/S`. Ancestors use `text/muted` and are links; the current page uses `text/primary`. The separator is `Icon/CaretRight` at 12. On mobile the row scrolls horizontally and never wraps. Each crumb's hit area is padded to 44 tall.
  - Index: হোম › খেলনা গাইড
  - Archive: হোম › খেলনা গাইড › {term title}
  - Guides: হোম › কেনাকাটার গাইড
- **Title:** `Display/L` (52) desktop, `Heading/H2` (32) mobile, `text/primary`. It wraps; it is never truncated.
- **Intro:** `Body/L`, `text/secondary`, max width 760. The data is the `intro` copy on the index and guides pages. On an archive it is `term.description` / `description_bn`. The intro is hidden when there is no description.
- **Category chips** (index and category archive only; hidden when the API returns no categories). Use a wrapping row on desktop (gap 10, as `34:6133`) and a single horizontal scroller on mobile (gap 8, edge-to-edge with 16 inset).
  - The first chip is **সব** (All). The selected chip copies `34:6134`: fill `bg/inverse`, label `text/inverse`, padding 8/18.
  - The other chips copy `34:6138`: fill `bg/surface`, `Label/M` `text/primary`, no icon (blog categories have no icon in the API).
  - Each chip has a count bubble with `posts_count` (the API sends it on `StorefrontBlogTerm`; the code does not show it yet, see §6), fill `pastel/sky-strong`, `Label/S` in `pastel/sky-ink`.
  - The selected chip's bubble has **no fill** and uses `text/inverse` (see token gap T1).
  - Chips are 40 tall on desktop and 44 on mobile.
- **Data:** chip label = `name` / `name_bn`. When the Bangla name is empty, the English name shows with `lang="en"` (Fredoka).

### 3.2 Card grid and pagination (list pages)

- **Grid:** desktop 3 columns of 384, gap 24 (exactly the Journal `34:5853`); 2 columns from 640; 1 column of 358 on mobile, gap 12. Vertical gap 24 / 12. Section padding: top 40, bottom 0.
- **Blog card:** copy the Journal `Post` `34:5854` and do not restyle it. Desktop: 384 wide. Mobile: 358 wide, cover at 16:10 (334 × 209), card padding 12, bottom padding 20.
  - Card: `bg/surface`, a 1 px stroke in `border/subtle`, `radius/xl`, padding 12/12/24, gap 16.
  - Cover: `radius/lg`, aspect 16:10 (358 × 220 in Figma; keep 220). Data: `cover.url`, alt `cover.alt` / `alt_bn`.
  - Text block: inset 12, gap 10.
  - Eyebrow (`Eyebrow` set `7:80`): the label is `category.name` / `name_bn`; when the post has no category and `type = buying_guide`, it is **কেনাকাটার গাইড**; otherwise the eyebrow is omitted.
  - Eyebrow tone: the API sends no colour. The code hashes the category slug over Butter · Sky · Lilac · Mint · Coral (coral renders pink in Vivid). In the frame, show three different tones.
  - Title: `Heading/H4` (20, Anek SemiBold), `text/primary`. It is clamped to **2 lines at a fixed height** so cards align; long Bangla titles end in "…". Data: `title` / `title_bn`.
  - Meta: `Caption`, `text/muted`: "{n} মিনিটে পড়ুন · {d} {month} {yyyy}". Data: `reading_minutes_bn` falls back to `reading_minutes`; the date is `published_at` as a Dhaka date with Western digits.
  - Interaction: the whole card is one link (via the title). Hover tints the title `text/brand`, and focus shows `SJ/Focus/ring` around the card.
- **Pagination:** below the grid, centred, padding top 32, bottom 16, gap 12.
  - Line: `Body/S` `text/secondary`, "{total}টি লেখার মধ্যে {from}–{to}". Data: `total` and the page window (12 per page).
  - Desktop: an instance copy of `34:6292`: 44 circles, prev/next carets, `…` gaps; the current page is filled `action/primary` with `text/on-primary`.
  - Mobile (`sm` down): the compact form. Prev circle · "পৃষ্ঠা 2 / 5" (`Label/M`) · Next circle. A disabled step uses `text/muted` with no fill.
  - Frame state: the desktop frame shows page 1 of 3; the mobile frame shows the compact form at page 2 of 5.
  - With a single page, only the line shows and there is no nav.

### 3.3 Archive variants (category, tag, author)

There is one frame, `Blog author — Desktop`, because the author header is the only archive header that differs. The category and tag archives are the §3.1 hero with a different title:
- **Category:** title = `{name}`. The chips are shown, with this category selected.
- **Tag:** title = “{name}” ট্যাগের লেখা. No chips.
- **Author:** title = {name}-এর লেখা. No chips. The intro is the author's `description`. The API also sends `avatar` on `StorefrontBlogTerm`. **Proposed:** a 72 circle avatar left of the title (horizontal, gap 20, centred). This changes the code, which does not show the avatar yet, but it needs no new API. When `avatar` is null there is no placeholder: the title sits flush left.

### 3.4 Guides index

The Blog index frame applies, with these differences: the title is কেনাকাটার গাইড, the intro is guidesIntro, there are **no chips**, and every card's eyebrow is the post's category (or কেনাকাটার গাইড). Note this in the frame annotation; there is no frame of its own.

### 3.5 Article page (`blog/[slug]`)

Top to bottom. The desktop content column for reading is **760** centred (x 340). Wide elements are 1200.

1. **Breadcrumb strip:** an instance copy of the product Breadcrumb `34:6396` (1440 × 62, crumbs at x 120). Crumbs: হোম › খেলনা গাইড › {category} › {post title}. The title crumb is truncated at 240 with "…". On mobile it is a 16-gutter scroller.
2. **Article header:** in the 760 column, vertical, gap 16, padding top 24, bottom 32. No band and no confetti: the reading page stays calm (see §6 Q3).
   - Eyebrow: the category, toned like the card's. Guides add a second eyebrow, কেনাকাটার গাইড (Sky).
   - H1: `Heading/H1` (40) desktop, `Heading/H2` (32) mobile, `text/primary`. Data: `title` / `title_bn`. Long Bangla wraps to 3–4 lines with `.lang-bn` line height (rule 38).
   - Lead: the `excerpt` / `excerpt_bn` in `Body/L` `text/secondary`. Hidden when null.
   - Meta row: horizontal and wrapping, gap 8 × 4, `Body/S` `text/secondary`.
     - Optional author avatar, 32 circle (`author.avatar`).
     - "লিখেছেন {name}": a link in `text/brand`, underlined on hover, going to the author archive.
     - · "প্রকাশিত {date}" · "{n} মিনিটে পড়ুন".
     - Separators are `·` in `text/muted`.
     - No author means the byline and avatar are dropped.
3. **Cover:** 1200 × 675 (16:9) desktop, `radius/2xl`, fill `pastel/sky` under the image; mobile 358 × 201, `radius/lg`. Data: `cover` (it carries AVIF/WebP srcsets). With no cover, the section is gone and the body follows the header.
4. **Body (prose):** the 760 column, vertical, gap 20, padding top 40. Use a specimen that shows every element the sanitiser allows (`p h2 h3 strong em ul ol li blockquote a img br`; there is no table, figure, caption or embed):
   - `p`: `Body/L` in `text/primary`, Bangla with `.lang-bn` line height.
   - `h2`: `Heading/H3` (24), 16 above it. `h3`: `Heading/H4` (20).
   - `ul`/`ol`: indent 24, item gap 4; bullets and numbers in `text/brand`.
   - `blockquote`: fill `pastel/sky`, a 4 px left bar in `brand/lagoon` (blue in Vivid), `radius/md`, padding 16/20, `Body/L` italic-free in `text/secondary`. Bangla has no italics.
   - `a`: `text/brand`, underlined.
   - `img`: full column width, height auto, `radius/lg`. There is no caption (the API has no figcaption).
   - **Bangla fallback:** when `body_bn` is empty on `/bn`, a `Caption` line in `text/muted` sits above the body: "ইংরেজিতে দেখানো হচ্ছে — বাংলা শিগগিরই আসছে". The body then renders in English (Latin line height).
5. **Tags:** a wrapping row, gap 8. Each tag is a chip, "#{name}", fill `bg/surface` with `SJ/Elevation/e1`, `Label/M`, 36 tall desktop and 44 mobile, linking to the tag archive. Hidden when `tags` is empty.
6. **Share row (proposed, not in code; see §6 Q1):** a horizontal row, gap 8, label "শেয়ার করুন" in `Label/M` `text/secondary`. Then `Icon Button` (`5:161`) Surface/40 × 4: Facebook, WhatsApp, Messenger, and copy link. Facebook, WhatsApp and Messenger are plain share URLs, so they work without JS. Copy link needs JS and is hidden without it; after a copy, a status "লিংক কপি হয়েছে" shows. On mobile the buttons are 48. Each icon button has an `aria-label` (copy table).
7. **Author box:** a card in the 760 column: `bg/surface`, a 1 px stroke in `border/subtle`, `radius/xl`, padding 24, horizontal, gap 16, top aligned.
   - Avatar: a 64 circle (`author.avatar`). With no avatar the image is omitted and no initials are drawn.
   - Text, gap 4:
     - Overline "লেখক সম্পর্কে" in `text/muted`.
     - Name: `Heading/H4`, linking to the author archive.
     - Bio: `bio` / `bio_bn` in `Body/S` `text/secondary`. Hidden when null.
   - Hidden entirely when `author` is null.
8. **Toys from this article:** a full-width section, padding top 72, laid out as "You may also like" `34:6669`.
   - `Section Header` (`9:213`, Desktop 1200 / Mobile 358): title "এই লেখার খেলনা", no eyebrow, no see-all.
   - Cards: `Product Card` (`9:146`) 4-up at 282, gap 24. Mobile: a swipe row of 171 cards (as `34:6762`).
   - Data: `related_products[].product`, a full listing card with live price and stock. The API omits unbuyable products, and the section is hidden when the list is empty.
   - This is content, not checkout, so rule 25b permits it.
9. **Keep reading:** padding top 72, bottom 96. `Section Header` titled "আরও পড়ুন". The grid is the §3.2 grid: 3 × 384 desktop, 1-col stacked on mobile. Data: `related_posts`, the same card fields. The frame shows 3. The section is hidden when the list is empty.

### 3.6 Buying guide page (`guides/[slug]`)

This is the article page (§3.5) with these changes:
- **Breadcrumb:** হোম › কেনাকাটার গাইড › {category} › {title}.
- **Header meta:** two more chips after the meta line. The code shows them as plain text; this spec proposes pills that match the section chips.
  - "{range} বয়সের জন্য": fill `pastel/mint`, `Label/S` in `pastel/mint-ink`. Data: `guide_age_band` (e.g. `24-48` → "2–4 বছর") through the listing's age labels.
  - Budget: fill `pastel/butter-strong`, `Label/S` in `text/on-butter`. Its value is an API decimal formatted, never computed (rule 1). It has three forms: "বাজেট ৳500–৳1,500", "বাজেট ৳1,500 পর্যন্ত" or "বাজেট ৳500 থেকে".
  - Each chip is hidden when its field is null.
- **Intro body:** as §3.5.4.
- **Sections:** repeated per item of `sections[]`, vertical, gap 16, padding top 48. Each section has:
  - H2: `Heading/H2` (32) desktop, `Heading/H3` (24) mobile. Data: `heading` / `heading_bn`.
  - Age and budget chips: same as the header chips, from `age_band` / `budget_min` / `budget_max`.
  - Body: §3.5.4 prose, from `body` / `body_bn`.
  - **Our picks:** a 2-column grid, gap 24 desktop / 16 mobile.
    - Each item is a `Product Card` stretched to fill the column: 368 on desktop, the 171 Mobile variant on mobile.
    - Under each card sits the note "**কেন বাছাই করেছি:** {note}", `Body/S` `text/secondary`, with the lead-in in `Label/M` `text/primary`. The note is hidden when `note` / `note_bn` is null.
    - Data: `picks[].product` (live price and stock; unbuyable products are omitted by the API).
  - The frame shows two sections: one with 2 picks and notes, and one with 3 picks, where one has no note. This shows the uneven row and the 2-col wrap.
- After the sections come the tags, share, author box, Toys from this article and Keep reading, exactly as the article.

## 4. States

| State | Where | Frame? | Behaviour |
|---|---|---|---|
| Empty list | `blog` and `guides` only (an archive with 0 posts is a 404) | **Yes**, `Blog — Empty — Mobile`; desktop is the same card at 1200 | The grid is replaced by a card: `bg/surface`, `SJ/Elevation/e1`, `radius/xl`, padding 24, gap 12, left aligned. H3 "এখানে এখনো কিছু নেই", `Body/M` "নতুন গাইড আসছে। ততক্ষণ বয়স অনুযায়ী খেলনা দেখুন।", and `Button` Primary/M "খেলনা দেখুন" (→ `/categories`). The pagination line is hidden |
| Page past the end, bad `?page`, unknown slug, wrong type | all | No | 404 (spec 03) or a 308 to the right section |
| Card without cover | list, Keep reading | Show one in the Blog desktop frame | The cover area is filled `pastel/sky` with `Icon/PuzzlePiece` at 40 in `icon/muted`, centred |
| Card without category (article) | list | No | No eyebrow; the title moves up and the card height still aligns in the row |
| No author / tags / cover / related | article | No | Each block is omitted, with no placeholder (§3.5) |
| Bangla body missing | article, guide section | Annotate on the article mobile frame | The fallback caption; English body with `lang="en"` |
| English fallback in names | chips, crumbs, eyebrow | No | Fredoka with Latin line height |
| Long Bangla | all | Build into the frames | Use one long title, for example "সব বাজেটের জন্মদিনের উপহার গাইড, ৳500 থেকে ৳5,000 — বয়স অনুযায়ী বাছাই করা সেরা খেলনা". It clamps to 2 lines on a card, wraps to 3 lines in the article H1, and the breadcrumb truncates it at 240 |
| Loading | — | No | Server-rendered; there is no skeleton |
| No JS | all | No | Everything is a link and pagination is real `<a>`; only the proposed copy-link button is hidden |
| Signed out / in | — | No | No difference |

## 5. Copy

All but the proposed rows exist today in `lib/blog/messages.ts`. The Bangla is marked `REVIEW(bn)` there: it is a first draft that needs a native reader.

| Key | Bangla | English |
|---|---|---|
| title | খেলনা গাইড | Toy guide |
| intro | বাংলাদেশের অভিভাবকদের জন্য সৎ পরামর্শ: কী কিনবেন, কোন বয়সের জন্য, আর কেন। | Honest advice for parents in Bangladesh: what to buy, for which age, and why. |
| guidesTitle | কেনাকাটার গাইড | Buying guides |
| guidesIntro | বয়স ও বাজেট অনুযায়ী বাছাই করা খেলনা, আমাদের দোকানের হালনাগাদ দামসহ। | Hand-picked toys by age and budget, with live prices from our shop. |
| home | হোম | Home |
| breadcrumb (aria) | ব্রেডক্রাম্ব | Breadcrumb |
| all | সব | All |
| categoriesNav (aria) | ব্লগের বিভাগ | Blog categories |
| readTime | {minutes} মিনিটে পড়ুন | {minutes} min read |
| by | লিখেছেন {name} | By {name} |
| publishedOn | প্রকাশিত {date} | Published {date} |
| categoryTitle | {name} | {name} |
| tagTitle | “{name}” ট্যাগের লেখা | Posts tagged “{name}” |
| authorTitle | {name}-এর লেখা | Posts by {name} |
| empty | এখানে এখনো কিছু নেই | Nothing here yet |
| emptyBody | নতুন গাইড আসছে। ততক্ষণ বয়স অনুযায়ী খেলনা দেখুন। | New guides are on the way. Meanwhile, browse toys by age. |
| browse | খেলনা দেখুন | Browse toys |
| tags (aria) | ট্যাগ | Tags |
| aboutAuthor | লেখক সম্পর্কে | About the author |
| relatedPosts | আরও পড়ুন | Keep reading |
| relatedProducts | এই লেখার খেলনা | Toys from this article |
| ourPicks (aria) | আমাদের বাছাই | Our picks |
| forAges | {range} বয়সের জন্য | For ages {range} |
| budget | বাজেট {range} | Budget {range} |
| budgetUpTo | {max} পর্যন্ত | Up to {max} |
| budgetFrom | {min} থেকে | From {min} |
| whyPicked | কেন বাছাই করেছি | Why we picked it |
| pagination (aria) | পৃষ্ঠা নির্বাচন | Pagination |
| previous / next (aria) | আগের পৃষ্ঠা / পরের পৃষ্ঠা | Previous page / Next page |
| page / pageCurrent (aria) | পৃষ্ঠা {page} / পৃষ্ঠা {page}, বর্তমান পৃষ্ঠা | Page {page} / Page {page}, current page |
| pageOf | পৃষ্ঠা {page} / {total} | Page {page} of {total} |
| showingRange | {total}টি লেখার মধ্যে {from}–{to} | Showing {from}–{to} of {total} posts |
| guideBadge | কেনাকাটার গাইড | Buying guide |
| fallbackLang | ইংরেজিতে দেখানো হচ্ছে — বাংলা শিগগিরই আসছে | Shown in English — Bangla coming soon |
| *share* (proposed) | শেয়ার করুন | Share |
| *shareFacebook* (proposed, aria) | Facebook-এ শেয়ার করুন | Share on Facebook |
| *shareWhatsApp* (proposed, aria) | WhatsApp-এ শেয়ার করুন | Share on WhatsApp |
| *shareMessenger* (proposed, aria) | Messenger-এ পাঠান | Send on Messenger |
| *copyLink* (proposed, aria) | লিংক কপি করুন | Copy link |
| *linkCopied* (proposed, status) | লিংক কপি হয়েছে | Link copied |

**Frame sample content** (data, not i18n). Reuse the Journal titles and dates: "একটা নরম বল দিয়ে খেলার পাঁচটি সেন্সরি খেলা" (4 min, 18 Nov 2026), "কেন ম্যাগনেটিক টাইলস সেরা প্রথম ইঞ্জিনিয়ারিং খেলনা" (STEM, 6 min, 12 Nov 2026), plus the long gift-guide title above. Use the Journal's sample categories (বয়স 0–2, STEM, উপহার). Product names stay English (e.g. Pastel Magnetic Building Tiles). The author is a placeholder name and is marked `placeholder`.

## 6. Deviations and open questions for the owner

**Token gaps**
- **T1:** the selected chip's count bubble. The Category hero `34:6136` binds the primitive `ink/700` on `bg/inverse`, which is a rule 26 defect. There is no semantic token such as `bg/inverse-subtle`. This spec drops the bubble fill on the selected chip until one is added.
- **T2:** there is no 18 px reading style. The article body uses `Body/L` (16), the same as the code's `t-body-lg`. If the owner wants a roomier reading size, add `SJ Vivid/Body/XL` (18, with Bangla at 1.15×).
- **T3:** icons. Check that the `4:2` Icons set has Facebook, WhatsApp, Messenger and Link (Phosphor `FacebookLogo`, `WhatsappLogo`, `MessengerLogo`, `Link`). The footer has social glyphs, but a missing one must be added from `@phosphor-icons/core`, never hand-drawn (Figma rule 15).
- **Storefront side** (known, §16.3): `border/subtle` and `radius/2xl` have no storefront token. The code renders `line-default` and 32.

**Deviations from today's code** (layout only; data and behaviour are unchanged)
- List header: the Bloom-sky gradient card becomes the full-bleed Category hero band, with the breadcrumb inside it.
- Chips gain the `posts_count` bubble. The API already sends it.
- Article: the gradient header card is dropped for a plain header in the 760 column. The cover goes to 1200 wide.
- Guide header: the age and budget values become pills.
- The author archive shows the term avatar.
- The blockquote gets a tint and a brand bar, replacing the grey border.

**Not designed — needs API**
- A featured lead card on the index: `is_featured` exists, but the list is ordered newest first and the API gives no "featured first" list for pages.
- View counts, likes, comments, author social links.
- A "last updated" line: `modified_at` exists only in SEO/JSON-LD. Showing it is a choice for the owner, not missing data.
- Image captions: the sanitiser allows no `figure` or `figcaption`.

**Questions**
1. **Share row:** the brief lists share, but the page has none today. Add Facebook, WhatsApp and Messenger plus copy link (no API needed)? No share counts.
2. **Guide contents list:** add an "এই গাইডে" jump list built from `sections[].heading`? It needs no new API.
3. **Confetti:** should the list hero carry it (as drawn, copying the Category hero), and should the article header stay plain (as drawn)?
4. **Chip counts:** show `posts_count` on the category chips?
5. **Author:** do real author names, bios and avatars exist? The frames use a `placeholder` author.
