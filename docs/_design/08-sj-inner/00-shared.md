# Smart Junior inner pages — shared patterns, numbering and build order

**Status:** specs written 2026-11-25 (batch brief `docs/_handoff/briefs/brief-p10-sj-inner-pages.md`); Figma frames not built yet.
**Owner decision (2026-11-25):** Figma first. These pages are designed on the **Vivid · বাংলা** page `34:5658` of the
Smart Junior file `2b6PVOOapPbcaKYIdHWZup`, the owner approves, then they are coded.

| Spec | Pages |
|---|---|
| `01-account-auth.md` | sign in, create account, enter code, set password, reset password |
| `02-account-area.md` | account home, orders, order detail, profile and kids, addresses, wishlist |
| `03-track-and-system.md` | track order, payment status, not found, server error, maintenance |
| `04-blog-and-guides.md` | blog index and archives, article, guides |
| `05-browse-and-info.md` | search, brands, all categories, CMS static pages, About, Contact, delivery charge |

## 1. Final frame numbering (overrides the numbers inside the specs)

| No. | Page | Spec | Spec's own number |
|---|---|---|---|
| 07 | Sign in (Password tab + SMS code tab) | 01 | 07 |
| 08 | Create account | 01 | 08 |
| 09 | Enter the code | 01 | 09 |
| 10 | Set your password | 01 | 10 |
| 11 | Reset your password | 01 | 11 |
| 12 | Account home | 02 | 08 group |
| 13 | Orders | 02 | 08 group |
| 14 | Order detail | 02 | 08 group |
| 15 | Profile and kids | 02 | 08 group |
| 16 | Addresses | 02 | 08 group |
| 17 | Wishlist | 02 | 08 group |
| 18 | Track order | 03 | 30 |
| 19 | Payment status | 03 | 31 |
| 20 | Not found | 03 | 32 |
| 21 | Server error | 03 | 33 |
| 22 | Maintenance | 03 | 34 |
| 23 | Blog index, archives (category, tag, author), guides index | 04 | 10 sub-row A |
| 24 | Blog article and buying guide | 04 | 10 sub-row B |
| 25 | Search (results, no results, suggestions) | 05 | 31 |
| 26 | Brands (index, brand header, empty) | 05 | 32 |
| 27 | All categories | 05 | 33 |
| 28 | CMS static pages (article layout, FAQ, stacked) | 05 | 34 |
| 29 | About | 05 | 35 |
| 30 | Contact (+ message sent, form errors) | 05 | 36 |
| 31 | Delivery charge | 05 | 37 |

**Placement.** Rows go below Order confirmed (bottom edge y ≈ 25305) in the order above, starting at y ≈ 25755. One row per
page; the next row's top is the tallest frame in the row + 300. Label frame `Label — NN · Page` at row y − 150, x 0 (as
`34:7363`). Columns: Desktop x 0 · Mobile x 1840 · extra states x 2340, 2840, 3340 … (500 apart). After each row, compute
bounding boxes and assert no intersection with any other top-level frame (Figma rule 3).

## 2. Build these first (tokens and components, page `2:4`)

Every spec hit the same gaps. Build them once, before any page frame, so the pages use instances.

**Tokens (`SJ · Color`, Vivid mode values from the `smart-junior` code theme; light mode too).**
- `feedback/danger`, `feedback/danger-subtle`, `text/danger`, `border/danger`, `text/on-danger`: a red ramp kept apart from
  sale pink, as in code (§16). **Rebind `Input` (`7:178`) Error from `text/sale` to these.**
- `feedback/warning-subtle` + `text/on-warning` (contact failure banner; spec 05 used butter as a stand-in).
- A semantic token for the selected-chip count bubble (spec 04 T1: the Category hero binds primitive `ink/700`, a rule 26 defect).
- Confirm `space/10`, spacing above `space/32` (code uses 48 and 80), `SJ/Elevation/e3`, and the exact `SJ Vivid/*`
  text-style names (the read tools returned only `SJ Vivid/Body/M`; specs name styles by role).

**Icons (`4:2`, Phosphor fill, from `@phosphor-icons/core`, Figma rule 15).** WarningCircle, SignOut, PencilSimple, Buildings,
Heart (fill variant), Smiley, FacebookLogo, WhatsappLogo, MessengerLogo, Link. Check which already exist first.

**Components.**
| Component | Variants | Used by |
|---|---|---|
| Notice | Info · Success · Warning · Danger | 01, 02, 03, 05 |
| Status Pill | the order stages + cancelled/returned (7 per spec 02; tones from spec 03) | 02, 03 |
| Order Timeline (vertical) | step: Done · Current · Upcoming · Cancelled; with courier line | 02, 03 |
| Code Boxes (OTP) | 6 boxes: Empty · Filled · Error | 01 |
| Segmented Control | 2–3 options (Password \| SMS code tabs, language picker) | 01, 02 |
| Switch | Off · On · Disabled | 02 |
| Select | Default · Focus · Error · Disabled (Input + CaretDown); Input gets a Disabled variant | 02, 05 |
| Chip | add an optional count slot | 02, 04 |
| Avatar | photo · initials; 40 / 64 | 02, 04 |
| Breadcrumb | desktop · mobile (from `34:6109` / `34:6316`) | all |
| Page Header Band | tones sky · mint · blush · butter; with/without confetti, eyebrow, lead | 04, 05 |
| Empty State | icon art + title + body + buttons (replaces Toy Mart `EmptyState` 80:1589) | 02, 03, 04, 05 |
| Account Menu Card | items with pastel icon tiles; active = fill + start bar | 02 |
| Brand Card · Category Card · FAQ Item (Closed/Open) · Search Suggestions (Results/Recent/No match) | as spec 05 | 05 |

**Shared page chrome** (spec 05 §0 is the reference): desktop Header `10:289` + Vivid footer `41:9166`; mobile Header + footer +
Bottom Tab Bar `10:290`. Account inner pages and "Enter the code" use the cart-style mobile top bar (`34:6923`) — open question
O3 below. Card = `bg/surface`, `radius/xl`, `border/subtle`, `SJ/Elevation/e1`, padding 28 / 20. Section rhythm 48 desktop / 32
mobile; the body ends 80 above the footer on desktop, 36 on mobile.

## 3. Rules every frame follows
- Bangla-first copy with the English beside it in the spec (§7.3: product names, brands, phone and order numbers stay English).
- Western numerals (rule 36). Bangla line-height ≈ 1.15× (rule 38) — set per style, `lineHeight` as PERCENT (Figma rule 7).
- No unconfirmed business value drawn as real: hotline, WhatsApp, email, address, hours, map, delivery charges and times,
  free-delivery threshold, "7-day returns". Draw them as visible placeholders (or use what the local API returns, for
  delivery figures). The 500 page and global error show **no** phone at all (owner, 2026-11-15).
- Tracking needs order number + phone; a failed lookup never says which field was wrong (rule 22).
- No recommendations on checkout (25b) — none of these pages is checkout.
- Fills cleared on structural containers, no raw colour including strokes, nothing overlaps, mutations strictly sequential,
  fonts reloaded per script (Figma rules 1–5).

## 4. Open questions for the owner (from all five specs)

Asked with the frames; nothing here blocks the build — each is drawn as the spec's default and marked.

**Brand and business values**
- O1. ~~Order number prefix~~ **Decided (owner, 2026-11-25): `SJB`**, same format: `SJB-26-00042`. Frames use it. Code still issues `TMB-`; the switch (generator, track/contact validation, storefront `ORDER_NUMBER`, i18n samples, old `TMB-` orders still trackable) is a separate code task.
- O2. Real hotline, WhatsApp number, email, support hours ("9 am – 9 pm" is unconfirmed), shop address. Until set, error and
  help copy uses no-phone wording (`track.tooMany`, three `payment.reasons`, verify help card).
- O3. ~~Brand names in Bangla~~ **Decided (owner, 2026-11-25): English** on both locales for payment and courier brands (bKash, Nagad, Rocket, Pathao, Steadfast …), per §7.3. Code to change: cart `payment.channels` in bn, courier `name_bn` on tracking.

**Layout choices**
- O4. Mobile top bar (slim, back button) on "Enter the code" and account inner pages, like cart/checkout? (Specs: yes for
  account inner pages; open for verify.)
- O5. Confetti on page header bands (blog list, static pages, search, brands), the 404, the account hello card? §7.4 limits it
  to listed sections. Specs: on list/info headers, off on article, 404, auth and account.
- O6. Delivery charge page full width at 1440 (estimator + zone table side by side) instead of today's 480 column?
- O7. Delivery details on mobile tracking (hidden today)?

**Content and copy**
- O8. Three sign-in strings contradict the 2026-11-20 decision (set a password later in Profile; "name and mobile only";
  "no separate sign-up form"). Spec 01 proposes replacements.
- O9. "Forgot password" on sign-in opens the SMS tab; from "already registered" it opens reset-password. Make both go to reset?
- O10. Unpaid online orders show the raw method code ("sslcommerz"): show "পেমেন্ট বাকি / Payment pending" instead?
- O11. Address cards: add the division (API sends it, rule 31)?
- O12. 404 button "Browse all toys" links to `/categories`; empty brand button says "Browse all categories" but links to
  `/brands`. Fix label or target?
- O13. Blog: share row (Facebook, WhatsApp, Messenger, copy link)? Guide contents jump list? Category chip post counts? Real
  author names, bios and photos?
- O14. Price colour in search suggestions: sale pink today even without a sale; spec uses `text/primary`.

**Code bugs found (fixed when coding after approval, unless the owner wants them sooner)**
- B1. The 500 page and global error still show the sample hotline in three places (owner decision 2026-11-15 says none).
- B2. Order detail never shows "Pay now", though the API sends `payment.retry_allowed` and the string exists.
- B3. The brands index ignores `logo_url`, which the API sends.

**Needs API (listed in the specs, not drawn):** product counts per brand/category, brand banner image, static-page header
tone, invoice PDF, returns, reorder, delivery estimate, featured blog post, image captions, voice-call OTP fallback.

## 5. Built in phase A (2026-11-25)

Built in the Smart Junior file `2b6PVOOapPbcaKYIdHWZup`, Components page `2:4`. The new sets sit in one column at x 0, starting
at y 10570 below the footer concepts, 80 apart. The top-level bounding-box audit found **no intersections**, and every fill and
stroke in the new sets is bound to an `SJ` variable (0 raw paints). Page `0:1` was not touched. On `34:5658`, the only change
is the `34:6136` count bubble.

### 5.1 Tokens

**Primitives.** These are new in `SJ · Primitives` (hidden, scopes `[]`). They are the Tailwind red ramp, the same as code `sj-red`:
`red/50` #FEF2F2 · `red/100` #FEE2E2 · `red/500` #EF4444 · `red/600` #DC2626 · `red/700` #B91C1C · `red/900` #7F1D1D.

**Semantic tokens in `SJ · Color`.** Vivid values come from the code theme `data-theme="smart-junior"` (`packages/tokens/dist/tokens.css`).
SJ Light has no code theme, so Light uses the same red. Warning is the exception: in Light it uses the butter ramp so it matches
the Light pastels. No `codeSyntax` is set, because no variable in this file has one.

| Token | id | Light | Vivid | Code source | Scopes |
|---|---|---|---|---|---|
| `feedback/danger` | `VariableID:141:20` | red/700 #B91C1C | red/700 #B91C1C | `feedback-danger`, `bg-danger` | frame, shape fill |
| `feedback/danger-subtle` | `141:21` | red/50 #FEF2F2 | red/50 #FEF2F2 | `bg-danger-subtle` | frame, shape fill |
| `text/danger` | `141:22` | red/700 | red/700 | `feedback-danger` used as text | shape, text fill |
| `icon/danger` | `141:23` | red/700 | red/700 | (spec 01 asked for it) | shape fill, stroke |
| `border/danger` | `141:24` | red/600 #DC2626 | red/600 #DC2626 | `border-danger` | stroke |
| `text/on-danger` | `141:25` | white | white | `text-on-danger` | text fill |
| `feedback/warning-subtle` | `141:26` | butter/50 #FFF9E5 | sunshine/50 #FFFBEB | `bg-warning-subtle` | frame, shape fill |
| `text/on-warning` | `141:27` | butter/700 #7A5600 | sunshine/800 #92400E | none in code; matches `text-ink-butter` | shape, text fill |
| `bg/inverse-subtle` | `141:28` | ink/700 #3A3F63 | slate/700 #334155 | none; replaces primitive `ink/700` on `34:6136` | frame, shape fill |

**Contrast (WCAG 2.x, computed from the hex values).**

| Pair | Ratio | Need |
|---|---|---|
| `text/danger` on `bg/surface` | 6.47:1 | 4.5 ✓ |
| `text/danger` on `feedback/danger-subtle` | 5.91:1 | 4.5 ✓ |
| `text/danger` on Vivid `bg/canvas` (blue/50) / Light canvas (cream/50) | 6.07 / 6.32:1 | 4.5 ✓ |
| `text/primary` on `feedback/danger-subtle` (Vivid / Light) | 13.37 / 14.25:1 | 4.5 ✓ |
| `text/on-danger` on `feedback/danger` | 6.47:1 | 4.5 ✓ |
| `icon/danger` on `feedback/danger-subtle` | 5.91:1 | 3 ✓ |
| `border/danger` on white (non-text) | 4.83:1 | 3 ✓ |
| `text/on-warning` on `feedback/warning-subtle` (Vivid / Light) | 6.84 / 6.31:1 | 4.5 ✓ |
| `text/primary` on `feedback/warning-subtle` (Vivid) | 14.11:1 | 4.5 ✓ |
| `text/inverse` on `bg/inverse-subtle` (Vivid / Light) | 10.35 / 10.14:1 | 4.5 ✓ |
| Status pill: `pastel/sky-ink` on `pastel/sky`, `pastel/mint-ink` on `pastel/mint`, `text/secondary` on `bg/muted` (Vivid) | 6.59 / 6.81 / 9.45:1 | 4.5 ✓ |

The count bubble is `bg/inverse-subtle` on a `bg/inverse` chip. Its fill contrast is only 1.41:1, but it is decorative: the white
number on the bubble carries the meaning at 10.35:1.

**Rebinds.**
- `Input` (`7:178`), State=Error:
  - the field stroke changed from `text/sale` to `border/danger`
  - the help text changed from `text/sale` to `text/danger`
- The `Help row` and `Error icon`:
  - In every variant, the help text now sits in a `Help row` with an `Icon/WarningCircle` 14 (`icon/danger`). The icon is
    shown only in Error, so the error is never shown by colour alone.
  - Existing instance overrides survived. A check of 34:7043, 34:7077, 34:7205 and 34:7229 found the helper text unchanged.
- Input gained **State=Disabled** (`142:13544`):
  - field: `bg/muted` fill, `border/subtle` stroke
  - value: `text/disabled`
  - label: `text/muted`
- `34:6136`, the selected-chip count bubble in the Category hero: changed from the primitive `ink/700` to `bg/inverse-subtle`.

### 5.2 Confirmed foundations

- **`SJ · Space`:** 0 · 2 · 4 · 6 · 8 · **10** · 12 · 16 · 20 · 24 · 32 · **40 · 48 · 56 · 64 · 80 · 96 · 120**.
  - `space/10`, `space/48` and `space/80` all exist, so nothing was added.
  - There is no 28 and no 36. Spec values of 28 and 36 use the nearest step (Category Card padding 24).
- **Effects:**
  - **`SJ/Elevation/e3` exists**, as do e1, e2, e4, `SJ/Focus/ring`, `SJ/Glow/lagoon` and `SJ/Glow/coral`.
  - `SJ/Focus/ring` is a 3 px spread with radius 0. **A spread renders only when the node has Clip content on.** Every Focus
    variant built here clips. Do the same on any frame that gets the ring.
- **`SJ Vivid/*` text styles:** these are the exact names. Letter-spacing is 0 throughout, and line height is a percentage.

| Style | Font | Size | Line height |
|---|---|---|---|
| `SJ Vivid/Display/XL` | Hind Siliguri Bold | 64 | 125% |
| `SJ Vivid/Display/L` | Hind Siliguri Bold | 52 | 125% |
| `SJ Vivid/Heading/H1` | Hind Siliguri Bold | 40 | 129% |
| `SJ Vivid/Heading/H2` | Hind Siliguri Bold | 32 | 133% |
| `SJ Vivid/Heading/H3` | Hind Siliguri Bold | 24 | 138% |
| `SJ Vivid/Heading/H4` | Anek Bangla SemiBold | 20 | 143% |
| `SJ Vivid/Price/L` | Anek Bangla Bold | 28 | 127% |
| `SJ Vivid/Price/M` | Anek Bangla Bold | 18 | 138% |
| `SJ Vivid/Price/S` | Anek Bangla Bold | 16 | 138% |
| `SJ Vivid/Body/L` | Anek Bangla Regular | 16 | 184% |
| `SJ Vivid/Body/M` | Anek Bangla Regular | 14 | 182% |
| `SJ Vivid/Body/S` | Anek Bangla Regular | 13 | 177% |
| `SJ Vivid/Label/L` | Anek Bangla Bold | 16 | 149% |
| `SJ Vivid/Label/M` | Anek Bangla SemiBold | 14 | 149% |
| `SJ Vivid/Label/S` | Anek Bangla SemiBold | 12 | 149% |
| `SJ Vivid/Title/Card` | Anek Bangla SemiBold | 15 | 161% |
| `SJ Vivid/Caption` | Anek Bangla Medium | 12 | 161% |
| `SJ Vivid/Overline` | Anek Bangla ExtraBold | 12 | 138% |
| `SJ Vivid/Bangla/Heading` | Anek Bangla Bold | 32 | 155% |
| `SJ Vivid/Bangla/Body` | Anek Bangla Regular | 16 | 201% |

Note: the existing Vivid screens set raw fonts and bind no text style. The new components bind the `SJ Vivid/*` styles above.

### 5.3 Icons (set `4:2`, grid `4:4`)

- **Source:** `@phosphor-icons/core@2.1.1` from unpkg (bold weight; the Heart fill is from the fill set). Each icon was built
  with `createNodeFromSvg`.
- **Construction:** each is a 24 px component with its vector at `constraints: SCALE`, bound to `icon/default`.
- **Added:**

  | Icon | id |
  |---|---|
  | WarningCircle | `142:1836` |
  | SignOut | `142:1839` |
  | PencilSimple | `142:1842` |
  | Buildings | `142:1845` |
  | HeartFill | `142:1848` |
  | Smiley | `142:1851` |
  | MessengerLogo | `142:1854` |
  | Link | `142:1857` |

- **Already present:** `FacebookLogo` (`4:67`), `WhatsappLogo` (`4:64`) and the outline `Heart` (`4:16`). The set now has 62 icons.

### 5.4 Components

All new components use `SJ Vivid/*` styles, so they are meant for the Vivid pages. On the Components page they render in Light,
the page default. A temporary Vivid preview was checked and then deleted.

| Component | Node id | Variants / properties |
|---|---|---|
| Notice | `145:1863` | Tone = Info · Success · Warning · Danger. Bool **Title** (off). 480 fixed, set to Fill in use |
| Status Pill | `145:1892` | Stage = Placed · Confirmed · Processing · Shipped · Delivered · Cancelled · Returned. Tones from spec 03, icons from spec 02 |
| Order Timeline / Step | `146:1903` | State = Done · Current · Upcoming · Cancelled. Bools **Rail** (on) and **Courier line** (off). 440 wide, 28 disc |
| Code Boxes / Box | `146:1912` | State = Empty · Focus · Filled · Error (46 × 56) |
| Code Boxes | `146:1967` | State = Empty (first box focused) · Filled · Error (with the error line) |
| Segmented Control / Item | `147:1879` | Size = L 44 · M 36 × State = Selected · Default · Focus |
| Segmented Control | `147:1904` | Options = 2 · 3 × Size = L (358) · M |
| Switch | `147:1923` | Value = Off · On × State = Default · Focus · Disabled (48 × 28 track in a 48 × 44 hit area) |
| Select | `147:1964` | State = Default · Focus · Error · Disabled. Bool **Help** |
| Input (updated) | `7:178` | State = Default · Focus · Error · **Disabled** (new) |
| Chip (updated) | `7:146` | State = Default · Selected. New bool **Count** (off, so existing screens are unchanged) |
| Avatar | `148:1921` | Type = Initials · Photo × Size = 40 · 56 · 64 |
| Breadcrumb | `148:1946` | Size = Desktop · Mobile (44 hit rows). Bool **Level 2** (desktop) |
| Page Header Band | `149:2684` | Tone = Sky · Mint · Blush · Butter × Size = Desktop 1440 · Mobile 390. Bools **Confetti**, **Breadcrumb**, **Eyebrow**, **Lead** |
| Empty State | `149:2775` | Layout = Desktop · Mobile · Card. Swap **Icon**. Bools **Secondary button** (on) and **Chips** (off) |
| Account Menu Card / Item | `151:2653` | State = Default · Active · Focus. Swap **Icon** |
| Account Menu Card | `151:2654` | Single component, 280 wide: me row, 6 items (Overview active), sign out |
| Brand Card | `152:2742` | Size = Desktop 282 · Mobile 171 × Logo = Letter · Image × State = Default · Focus. Bool **Description** |
| Category Card | `152:2793` | Size = Desktop 384 · Mobile 358. Bool **Children**. Art bound to `SJ · Category tint` |
| FAQ Item | `153:2725` | State = Closed · Open · Focus (852 wide) |
| Search Suggestions | `153:2793` | State = Results · Recent · No match (600 wide) |

**Screenshot-verified:** Icons, Notice, Status Pill, Order Timeline / Step, Code Boxes, Segmented Control, Switch, Select,
Chip (count on), Avatar, Breadcrumb, Page Header Band, Empty State, Account Menu Card and its Item, Brand Card, Category Card,
FAQ Item, Search Suggestions. In Vivid mode: Notice, Status Pill, Timeline, Select Error, Switch, Code Boxes Error, Account item.

**Choices where the specs disagreed (each matches the existing Vivid frames or the stricter spec):**

1. **Timeline marker.**
   - 28 px, as Order confirmed `34:7283` and spec 02 (spec 03 said 24).
   - The Current ring and core are `action/primary`.
   - Stopped uses `feedback/danger` with a white X, per spec 03 (spec 02 used `text/secondary`).
2. **Breadcrumb.** Follows `34:6109`: `Label/S`, ancestors in `text/muted`. Spec 05 said `Body/S` in `text/secondary`.
3. **Status Pill.**
   - Placed to Shipped all use sky, per spec 03 (spec 02 had butter for the first three).
   - Cancelled uses the danger pair.
4. **Page Header Band.**
   - Uses spec 05's padding and H1 (spec 04 used `Display/L`).
   - Breadcrumb is on mobile too, as the Category hero `34:6316` (spec 05 had it desktop-only; turn it off per page).
   - On the Sky tone, the Sky eyebrow pill blends into the band. Its text stays legible.
5. **Brand Card letter.** `Heading/H2` 32 in Fredoka Bold. Spec 05's 36 has no style.
6. **Empty State.** One set covers both spec 05 (160 art) and spec 02 (Card: 72 disc).
7. **Avatar.** Includes 56, which the account menu uses, alongside the requested 40 and 64. The Photo variant is a placeholder
   (`bg/muted` + User icon). Put the image fill on the root.

**Known limits:**
- **Text with a Latin-only face.** These nodes set Fredoka on the whole string: digits, `LEGO`, initials, counts, the phone and
  the OTP digits. That detaches the `SJ Vivid` style, so they keep the role's size and line height but bind no style. Mixed
  Bangla + Latin strings keep their style, with a per-range Fredoka override.
- **Bangla labels in existing instances.** Bangla Chip and Eyebrow labels inside instances override the Latin `SJ/*` style of
  those older components to Anek Bangla.
- **Fix:** add `SJ Vivid/Latin/*` (Fredoka) styles, if the owner wants every node style-bound.

## 6. Built in phase B (frames 07–17, 2026-11-25)

Built on `34:5658` (Vivid · বাংলা), below Order confirmed. Every page frame sets `SJ · Color` = Vivid and `SJ · Type` = Vivid
explicitly, like the existing screens, and clips its own content (as they do); nothing inside clips except the 56 px
product thumbnails (image crops) and the focused OTP box. Chrome is cloned from the existing Vivid frames: desktop Header
`34:7266`, mobile Header `34:7308`, cart top bar `34:6923`, footers `41:13044` / `41:11108`, Bottom Tab Bar `34:6358`
(re-pointed to **অ্যাকাউন্ট** active on the account frames). Every new fill, stroke, gap, padding and radius is bound to an
`SJ` variable (audit: 0 raw paints outside instances); text uses the `SJ Vivid/*` styles with a per-range Fredoka override for
digits, Latin words, order numbers and phone numbers. Top-level bounding-box audit after the last row: **no intersections**.

### 6.1 Frames

| Frame | Node id | Size | Position (x, y) |
|---|---|---|---|
| Label — 07 · Sign in | `156:15327` | 605 × 88 | 0, 25605 |
| 07 · Sign in — Password — Desktop | `156:12533` | 1440 × 1782 | 0, 25755 |
| 07 · Sign in — Password — Mobile | `156:13227` | 390 × 2232 | 1840, 25755 |
| 07 · Sign in — Password — Error — Mobile | `156:13697` | 390 × 2366 | 2340, 25755 |
| 07 · Sign in — SMS code — Desktop | `156:14177` | 1440 × 1834 | 0, 28544 |
| 07 · Sign in — SMS code — Mobile | `156:14863` | 390 × 2164 | 1840, 28544 |
| Label — 08 · Create account | `157:16751` | 451 × 88 | 0, 30858 |
| 08 · Create account — Desktop | `157:15075` | 1440 × 1945 | 0, 31008 |
| 08 · Create account — Mobile | `157:15775` | 390 × 2395 | 1840, 31008 |
| 08 · Create account — Already registered — Mobile | `157:16253` | 390 × 2527 | 2340, 31008 |
| Label — 09 · Enter the code | `159:18630` | 1394 × 88 | 0, 33685 |
| 09 · Enter the code — Desktop | `159:16588` | 1440 × 1525 | 0, 33835 |
| 09 · Enter the code — Mobile | `159:17256` | 390 × 1663 | 1840, 33835 |
| 09 · Enter the code — Wrong code — Mobile | `159:17709` | 390 × 1694 | 2340, 33835 |
| 09 · Enter the code — Resend ready — Mobile | `159:18168` | 390 × 1747 | 2840, 33835 |
| Label — 10 · Set your password | `160:19637` | 769 × 88 | 0, 35732 |
| 10 · Set your password — Desktop | `160:18523` | 1440 × 1753 | 0, 35882 |
| 10 · Set your password — Mobile | `160:19202` | 390 × 1781 | 1840, 35882 |
| Label — 11 · Reset your password | `160:20781` | 557 × 88 | 0, 37813 |
| 11 · Reset your password — Desktop | `160:19640` | 1440 × 1830 | 0, 37963 |
| 11 · Reset your password — Mobile | `160:20333` | 390 × 1998 | 1840, 37963 |
| Label — 12 · Account home | `162:21922` | 671 × 88 | 0, 40111 |
| 12 · Account home — Desktop | `162:20639` | 1440 × 1664 | 0, 40261 |
| 12 · Account home — Mobile | `162:21409` | 390 × 2245 | 1840, 40261 |
| Label — 13 · Orders | `164:23574` | 792 × 88 | 0, 42656 |
| 13 · Orders — Desktop | `164:21801` | 1440 × 1789 | 0, 42806 |
| 13 · Orders — Mobile | `164:22606` | 390 × 2286 | 1840, 42806 |
| 13 · Orders — Empty — Mobile | `164:23126` | 390 × 1466 | 2340, 42806 |
| Label — 14 · Order detail | `167:24718` | 1203 × 88 | 0, 45242 |
| 14 · Order detail — Desktop | `167:23392` | 1440 × 1877 | 0, 45392 |
| 14 · Order detail — Mobile | `167:24197` | 390 × 2584 | 1840, 45392 |
| Label — 15 · Profile and kids | `168:26542` | 779 × 88 | 0, 48126 |
| 15 · Profile — Desktop | `168:24580` | 1440 × 2547 | 0, 48276 |
| 15 · Profile — Mobile | `168:25406` | 390 × 3279 | 1840, 48276 |
| 15 · Profile — Kids form open — Mobile | `168:25952` | 390 × 3619 | 2340, 48276 |
| Label — 16 · Addresses | `169:28157` | 706 × 88 | 0, 52045 |
| 16 · Addresses — Desktop | `169:26249` | 1440 × 2413 | 0, 52195 |
| 16 · Addresses — Mobile | `169:27083` | 390 × 2762 | 1840, 52195 |
| 16 · Addresses — Empty + errors — Mobile | `169:27636` | 390 × 2607 | 2340, 52195 |
| Label — 17 · Wishlist | `170:30049` | 826 × 88 | 0, 55235 |
| 17 · Wishlist — Desktop | `170:27938` | 1440 × 2261 | 0, 55385 |
| 17 · Wishlist — Mobile | `170:28783` | 390 × 2153 | 1840, 55385 |
| 17 · Wishlist — Empty — Desktop | `170:29347` | 1440 × 1526 | 2340, 55385 |

Row 07 has two sub-rows (Password, then SMS code 400 below, as spec 01 asks). Rows are the tallest frame + 300 apart, or more
where a row shrank after a fix.

**Screenshot-verified:** every row 07–17, section by section (desktop body, mobile body, each state frame), plus the
desktop header actions, the mobile header and the bottom tab bar zoomed in after the clipping fix (§6.4).

### 6.2 Deviations from the specs (and why)

**Sign-in and set-up (07–11)**
1. `Input` has no Large (52) size, so desktop forms use the component's 48 field. Benefits-panel padding is `space/40`
   (code value; the spec fell back to 32 only because 40 was not known to exist). Desktop body: padding 48 top / 80 bottom.
2. No `Cube` icon exists; the orders benefit uses `Package`. The verify back button uses `CaretLeft` (no `ArrowLeft`).
3. Mobile "forgot" link sits 12 below the password field (the card gap), not −6: no negative step exists.
4. Password Error frame: the `notices.failed` banner (hotline drawn as `[হটলাইন · placeholder]`) sits at the top of the form
   card **and** the field error is shown, as the spec asks for one banner example in that frame.
5. "Already registered": the Notice has no links slot, so that one Notice is **detached** (`Notice · Already registered
   (detached: links row)`) to add the two 44-tall links. Add a `Links` slot to `Notice` and swap back when convenient.
6. Required ` *` marks are a `text/danger` range on the Input label (no separate node).
7. "Enter the code" keeps the full site header with the in-page back row (spec 01 default; O4 still open). Default frame shows
   boxes 1–3 filled (4 8 2) and box 4 focused (the spec asked for first-box focus *and* three filled, which contradict).
   Wrong code keeps the component's API-style line "কোডটি মেলেনি। আবার লিখুন।". Resend ready uses an
   `ArrowCounterClockwise` icon in place of the clock. Small print for register/reset is listed in the row label.
8. Copy flagged by spec 01 §8 (passwordNote, benefits.trust, register.sub, panelBody "no separate sign-up form") is drawn
   **as shipped**, not rewritten (O8).

**Account area (12–17)**
9. Mock name is রাইসা আহমেদ (the Account Menu Card default) and order numbers are `SJB-26-000xx` (owner decision),
   replacing the spec's "SJ-260123".
10. Desktop order cards and address cards are 436 wide (2 × 436 + 16 = 888), not 432, so the grid fills the column.
11. The verified phone on Profile uses **`Select` State=Disabled with its trailing icon swapped to `Lock`** (Input has no
    trailing-icon slot). The "Help row" of an Input/Select is hidden whenever there is no helper text.
12. Wishlist grid is 3 × 280 with `space/24` gaps (888); there is no 36 step. Product cards are clones of the Category-page
    cards (live prices as drawn there), resized to 280 / 171. The share strip is `pastel/butter` (spec gave no fill).
13. Account-home mobile tiles get a common min-height (165) so the 2 × 3 grid rows line up.
14. Order tabs are `Chip` instances with Count on; their filter "×" is hidden. They sit at 36 inside a 44 row.
15. Order detail delivery charge shows ৳70 — this is a per-order value the API returns, not the unconfirmed rate card.
16. Profile shows `whatsappUnavailable` under `orderHow` (WhatsApp sending is not live yet). Birthday consent is **off**.
17. Address form: the 2-line textarea is the `Input` component at its normal height (resizing the nested field is not
    allowed on an instance); it is named `Textarea · … (2 lines)`. The empty + errors frame uses **NEW** copy
    "এলাকার নাম দিন।" for the Area field error (spec named no string) — confirm or replace.
18. Breadcrumb row is 20 / 120 padding (58 tall) instead of a clone of the product breadcrumb row.

### 6.3 Left out (not drawn)

- "Pay now" on order detail: conditional on `payment.retry_allowed`; the COD mock does not show it (noted in the row label).
- The other order stages, invoice, returns, reorder and all other "needs API" items from spec 02 §9.
- Voice-call OTP fallback, the expired-code banner and the session-ended / signed-out banners (spec 01 states with no frame).
- Signed-in header state on Set password (the cloned header is the signed-out one used across the Vivid page).

### 6.4 Clipping fix (owner feedback, 2026-11-25)

Badges and shadows were being cut off. Cause: clipping on structural frames inside components and on cloned chrome.
- **Components changed on `2:4`** (clip turned off on every non-Focus variant's structural frames; Focus variants untouched,
  image crops, the Page Header Band confetti layer and Category Card `Art` kept): Header `10:289` (39 frames, incl. the
  Wishlist / Cart wrappers that cut the count badges), Bottom Tab Bar `10:290` (11), Notice (8), Order Timeline / Step (20),
  Code Boxes (6), Switch (4), Select (6), Input (6), Chip (2), Avatar (3, Initials only), Breadcrumb (6), Page Header Band (8),
  Empty State (7), Account Menu Card / Item (2), Account Menu Card (4), Brand Card (8), Category Card (6), FAQ Item (6),
  Search Suggestions (17). Status Pill, Code Boxes / Box, Segmented Control, Button and Icon Button had none.
  The existing Vivid screens inherit this too (their header badges were cut the same way).
- **In frames 07–17:** 107 clip overrides turned off — cloned top bars (5), header instance overrides (`Mark`, `Frame`,
  `Submit`), the detached Notice (`Icon wrap`, `Content`) and product-card internals. Footer instances were left as they are
  (their clipping holds the decorative sky/confetti).
