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

## 7. Built in phase C (frames 18–24, 2026-11-25)

Built on `34:5658` (Vivid · বাংলা), below Wishlist. The conventions are the same as phase B (§6):
- Every page frame sets `SJ · Color` = Vivid and `SJ · Type` = Vivid explicitly, and clips only its own content.
- The chrome is cloned from the phase B frames (desktop Header `162:20640`, mobile Header `162:21410`, footers `162:20850` / `162:21448`) and from the Home Bottom Tab Bar `34:6012`, with হোম active.
- Text uses the `SJ Vivid/*` styles, with a per-range Fredoka override on Latin text and digits.

**Audits.**
- After the last row:
  - Top-level bounding-box audit: **no intersections**.
  - Raw (unbound) solid fills and strokes outside instances: **0**.
  - Clipping frames inside these rows: **0**. The confetti `Section pattern` layers and the 404 illustration are decorative and keep their clipping.
- The chips, buttons, inputs, steps and pills all keep 44 px targets wherever the component provides them.

### 7.0 Placeholders (owner, 2026-11-25)

The owner approved swapping the unconfirmed business values for marked placeholders in the shared Header `10:289` and
`Footer — Vivid · বাংলা` `41:9166`, and in the same values elsewhere on `34:5658`.

**How a placeholder is marked.**
- The value sits in an auto-layout frame named `placeholder:<what>`, with no fill and a 1 px dashed (4/3) inside stroke bound to `border/default`.
- The frame has `space/4` left and right padding, radius `radius/xs`, and `strokesIncludedInLayout = false`. Frames that wrap an instance (a button, Trust Item or Notice) also get `space/2` top and bottom padding.
- The text keeps its node, fill and text style. Bangla placeholder text uses Anek Bangla at the original weight and size; `COD` stays in Fredoka.
- Where the value sits inside a sentence, the frame wraps the whole text node and only the bracketed part is the placeholder.
- Where the value sits inside an instance of another component (a Button, Trust Item or Notice), the frame wraps that instance, because an instance cannot take a new child.

**Components (page `2:4`).** The Header is also used by 9 instances on the English page `Smart Junior`, so its main text uses English placeholders. The Vivid instances override them with Bangla.

| Value | Node | Placeholder frame | Main text | Vivid instances |
|---|---|---|---|---|
| Free-delivery threshold, desktop announcement | `10:137` | `195:2724` `placeholder:free-delivery-threshold` | Free delivery in Dhaka over [free-delivery threshold] | ঢাকায় [ফ্রি ডেলিভারির সীমা]-এর বেশি অর্ডারে ফ্রি ডেলিভারি (27) |
| Return window, desktop announcement | `10:145` | `195:2725` `placeholder:return-window` | [Return window] easy returns | [রিটার্নের মেয়াদ] সহজ রিটার্ন (27) |
| Hotline, desktop main bar | `10:180` | `195:2726` `placeholder:hotline` | [Hotline number] | [হটলাইন নম্বর] (26) |
| Free-delivery threshold, mobile announcement | `10:253` | `195:2727` `placeholder:free-delivery-threshold` | Free Dhaka delivery over [free-delivery threshold] · COD | ঢাকায় [ফ্রি ডেলিভারির সীমা]-এর বেশিতে ফ্রি ডেলিভারি · COD (24) |
| Hotline, footer contact | `41:8183` | `195:2728` `placeholder:hotline` | [হটলাইন নম্বর] | inherits (26) |
| Hours, footer contact | `41:8184` | `195:2729` `placeholder:hours` | [সময়সূচি] | inherits (26) |
| WhatsApp reply time ("15 মিনিটের মধ্যে উত্তর") | `41:8190` | `195:2730` `placeholder:reply-time` | [উত্তরের সময়] | inherits (26) |
| Email | `41:8195` | `195:2731` `placeholder:email` | [ইমেইল] | inherits (26) |
| Email reply time ("এক দিনের মধ্যে উত্তর দিই") | `41:8196` | `195:2732` `placeholder:reply-time` | [উত্তরের সময়] | inherits (26) |
| Address ("ধানমন্ডি, ঢাকা") | `41:8201` | `195:2733` `placeholder:address` | [ঠিকানা] | inherits (26) |
| Address line ("পিক-আপ পয়েন্ট ও স্টুডিও") | `41:8202` | `195:2734` `placeholder:address-note` | [ঠিকানার বিবরণ] | inherits (26) |

- The footer instances had no overrides on these nodes, so they took the main text directly.
- The header hotline instance overrides had already collapsed into the main value, so all 26 were set again.
- The footer contact column `41:8177` and its row and text frames no longer clip (rule 17). The four text stacks gained a `space/2` gap so the dashed outlines do not touch.
- The mobile footer `41:8832` shows no contact values: its contact accordion is collapsed. The mobile header has no hotline.

**Vivid page `34:5658`, outside the chrome.**

| Value | Text node | Placeholder frame | New text |
|---|---|---|---|
| Hours, Home desktop Help CTA | `34:5878` | `196:42101` `placeholder:hours` | … সাজেস্ট করব। [সময়সূচি]। |
| Hotline button, Home desktop Help CTA | `I34:5879;5:103` | `196:42116` wraps Button `34:5879` | [হটলাইন নম্বর] |
| Return window, Home desktop Trust Item | `I34:5704;7:205` | `196:42114` wraps Trust Item `34:5704` | [রিটার্নের মেয়াদ] সহজ রিটার্ন |
| Return window, Home mobile Trust | `42:12465` | `196:42103` `placeholder:return-window` | [রিটার্নের মেয়াদ]-এ রিটার্ন |
| Hours, Home tablet Help CTA | `34:6103` | `196:42102` `placeholder:hours` | [সময়সূচি] |
| Return window, Home tablet Trust Item | `I34:6040;7:205` | `196:42115` wraps Trust Item `34:6040` | [রিটার্নের মেয়াদ]-এ রিটার্ন |
| Hotline button, Product desktop "Order by phone" | `I34:6481;5:107` | `196:42117` wraps Button `34:6481` | কল করুন [হটলাইন নম্বর] |
| Return window, Product desktop delivery facts | `34:6509` | `196:42104` `placeholder:return-window` | [রিটার্নের মেয়াদ] সহজ রিটার্ন |
| Return window, Cart desktop summary | `34:6908` | `196:42105` `placeholder:return-window` | নিরাপদ চেকআউট · [রিটার্নের মেয়াদ] সহজ রিটার্ন |
| Hotline + hours, Cart desktop help | `34:6913` | `196:42106` `placeholder:hotline+hours` | কল করুন [হটলাইন নম্বর] · [সময়সূচি] |
| Hotline, Checkout desktop header | `34:7029` | `196:42107` `placeholder:hotline` | সাহায্য লাগবে? [হটলাইন নম্বর] |
| Return window, Checkout desktop reassurance | `34:7183` | `196:42108` `placeholder:return-window` | [রিটার্নের মেয়াদ] সহজ রিটার্ন, কোনো প্রশ্ন ছাড়াই |
| Hotline, 07 sign-in error Notice (mobile) | `I156:13743;145:1862` | `196:42118` wraps Notice `156:13743` | … অথবা [হটলাইন নম্বর]-এ ফোন করুন। |
| Hotline, 09 code help card ×4 | `159:16757`, `159:17338`, `159:17797`, `159:18259` | `196:42109`–`196:42112` `placeholder:hotline` | … অথবা [হটলাইন নম্বর]-এ ফোন করুন। |
| Hotline, 22 maintenance A body | `183:35985` | `196:42113` `placeholder:hotline` | … দরকার হলে [হটলাইন নম্বর]-এ ফোন করুন। |
| Hotline, 22 maintenance A call button | `I183:35987;5:55` | `196:42119` wraps Button `183:35987` | ফোন করুন [হটলাইন নম্বর] |

The phase B and C screens used `[হটলাইন · placeholder]` for the hotline. Those now read `[হটলাইন নম্বর]`.

**Left unchanged.**
- These are not shop contact or policy values:
  - customer phones and addresses (`01712 345 678`, Dhanmondi delivery addresses)
  - prices, delivery-slot times and order data
  - cash on delivery
  - footer links "রিটার্ন ও বদল" and "স্টোরের ঠিকানা"
  - the "যত্ন নিয়ে তৈরি, ঢাকায়" copyright line
  - "WhatsApp-এ চ্যাট করুন"-style buttons that show no number
- The flash-deal "প্রতিদিন সকাল 10টায় দাম কমে" (`9:184`) is a promotion schedule, not shop hours.
- The product free-delivery nudge "আর ৳210 যোগ করলে ঢাকায় ফ্রি ডেলিভারি" (`34:6484`, `34:6729`) implies the threshold, but the amount is cart-derived. The owner should confirm whether it gets a placeholder too.
- No screen shows a shop WhatsApp number, so `[WhatsApp নম্বর]` was not needed.
- The backup and concept footer sets (`102:1042`, `109:1796`, `111:1959`, `115:1932`) and the English `Footer` `11:376` were not touched.

**Audits.**
- Top-level bounding-box audit on `34:5658` (94 nodes): **no intersections**.
- All 19 page-level and 11 component-level placeholder strokes are bound to `border/default`.
- Zoomed screenshots checked:
  - header desktop (announcement and hotline) and mobile (announcement)
  - the footer contact column, in a Vivid instance and on the component
  - every body-copy spot in the tables above

### 7.1 Frames

| Frame | Node id | Size | Position (x, y) |
|---|---|---|---|
| Label — 18 · Track order | `175:31780` | 1848 × 88 | 0, 57796 |
| 18 · Track — Desktop | `173:29951` | 1440 × 2046 | 0, 57946 |
| 18 · Track — Mobile | `174:31200` | 390 × 2967 | 1840, 57946 |
| 18 · Track — Lookup + error — Mobile | `174:31692` | 390 × 1816 | 2340, 57946 |
| 18 · Track — Timeline specimens | `175:31596` | 736 × 1545 | 2840, 57946 |
| 18 · Track — Lookup — Desktop main column (specimen) | `175:31773` | 736 × 260 | 3676, 57946 |
| Label — 19 · Payment status | `176:33832` | 2041 × 88 | 0, 61064 |
| 19 · Payment — Desktop · failed | `176:31742` | 1440 × 2275 | 0, 61214 |
| 19 · Payment — Mobile · failed | `176:33163` | 390 × 2696 | 1840, 61214 |
| 19 · Payment — Mobile · states board (pending, expired, review, no order) | `176:33685` | 390 × 3274 | 2340, 61214 |
| 19 · Payment — Desktop · paid | `176:32444` | 1440 × 2520 | 2840, 61214 |
| Label — 20 · Not found | `178:34911` | 1762 × 88 | 0, 64638 |
| 20 · Not found — Desktop | `177:33438` | 1440 × 2399 | 0, 64788 |
| 20 · Not found — Mobile | `177:34148` | 390 × 2606 | 1840, 64788 |
| Label — 21 · Server error | `182:36018` | 2356 × 88 | 0, 67545 |
| 21 · Server error — Desktop | `182:34911` | 1440 × 1475 | 0, 67695 |
| 21 · Server error — Mobile | `182:35554` | 390 × 1807 | 1840, 67695 |
| Label — 22 · Maintenance | `183:35998` | 2226 × 88 | 0, 69653 |
| 22 · Maintenance — Desktop (variant B) | `183:35898` | 1440 × 900 | 0, 69803 |
| 22 · Maintenance — Mobile (variant B) | `183:35932` | 390 × 844 | 1840, 69803 |
| 22 · Maintenance — Contacts confirmed — Mobile (variant A) | `183:35966` | 390 × 844 | 2340, 69803 |
| Label — 23 · Blog index & archives | `186:38707` | 2961 × 88 | 0, 70853 |
| 23 · Blog — Desktop | `184:35951` | 1440 × 2277 | 0, 71003 |
| 23 · Blog — Mobile | `186:37565` | 390 × 3244 | 1840, 71003 |
| 23 · Blog — Empty — Mobile | `186:38089` | 390 × 1653 | 2340, 71003 |
| 23 · Blog author — Desktop | `184:36704` | 1440 × 1727 | 2840, 71003 |
| Label — 24 · Blog article & guide | `190:43518` | 2902 × 88 | 0, 74398 |
| 24 · Blog article — Desktop | `187:38639` | 1440 × 4588 | 0, 74548 |
| 24 · Blog article — Mobile | `190:40459` | 390 × 5072 | 1840, 74548 |
| 24 · Buying guide — Mobile | `190:41780` | 390 × 5884 | 2340, 74548 |
| 24 · Buying guide — Desktop | `189:39447` | 1440 × 5581 | 2840, 74548 |

**Layout.**
- Rows are the tallest frame + 300 apart.
- Row 19 was moved down once, after the Track mobile frame grew in a fix.
- The next free row starts at about y 80430.

**Screenshot-verified:** every frame in rows 18–24. Zoomed checks covered:
- the desktop header actions and badges
- the order card and fact tiles on mobile
- the lookup form with the error Notice
- the timeline specimens
- the payment receipt card and the "What happens next" card
- the 404 eyebrow, hero and section headers
- the server-error card, with a check that no phone appears
- the maintenance A and B cards
- the blog hero band, the chips, the card title clamp and the pagination
- the article breadcrumb, header, meta row, tags, share row and author box
- the guide sections, with their picks and notes

### 7.2 Deviations from the specs (and why)

**Data and copy**
1. Order number `SJB-26-00042` (O1) replaces the spec's `TMB-`, and courier and payment brands stay English (O3).
2. Sample totals are internally consistent but are sample data:
   - track: ৳6,825 COD
   - payment: subtotal ৳7,430, coupon −৳675, delivery ৳70
3. The 500 page and maintenance B show no phone. Maintenance A draws the hotline as `[হটলাইন · placeholder]`.
4. The track help card drops the hours (D4).

**Track (18)**
5. Cards use `border/default` + `SJ/Elevation/e1`, radius `xl` / `lg`, padding 32 (24 for the timeline and rail cards) / 20.
6. The error callout is the `Notice` Danger instance (with WarningCircle), not a hand-built callout. The fields stay in their Default state (rule 22).
7. The status pill is `Status Pill` Stage=Shipped ("পথে আছে") for the out-for-delivery order.
8. The Upcoming step has no time line, because the component variant has none.
9. The desktop lookup state is drawn as a 736-wide specimen at x 3676, not under the desktop frame, to keep the row grid.

**Payment (19)**
10. The payment status pill is built from tokens, because `Status Pill` has only order stages.
11. The receipt card does not clip. Instead, the actions bar carries bottom radii (rule 17).
12. The flash callout after a failed retry is not drawn: the spec gives no copy.

**Not found (20)**
13. The 404 illustration is 440 × 236 on desktop (spec 560 × 300), so the copy column fits the search field. It is the code's SVG geometry rebound to tokens.
14. Categories and bestsellers are clones of Home "Shop by category" `34:5706` / `34:5915` and "You may also like" `34:6669` / `34:6762`, with the eyebrow and subheading hidden.

**Server error (21)**
15. The desktop header instance hides `Hotline` and `Category nav`. The desktop footer instance hides the four contact rows; the heading stays.
16. The mobile footer's contact accordion is collapsed, so no value shows there.

**Blog (23–24)**
17. The list hero is built from tokens rather than the `Page Header Band` component, because the chips must sit inside the band:
    - The band copies the Category hero, with the confetti `Section pattern` layer cloned.
    - Padding is 24 / 40 (no 28 or 36 step).
    - The chips are the phase B `Chip` (Count on) clones, 36 tall, not 40 / 44.
18. Blog cards are clones of the Journal `Post` `34:5854`. The title is fixed at 58 with ending truncation (2 lines).
19. Desktop pagination is a clone of `34:6292`; its current page is `bg/inverse` (as on Category), not `action/primary`.
20. Author avatars use `Avatar` Initials at 64 (archive header and author box) and 40 (byline). The spec asked for 72 and 32, sizes the component does not have.
21. Article "Toys from this article" padding top is 64 (no 72 step).
22. Guide picks are clones of the Vivid product cards, at 368 desktop and 171 mobile. The guide drops the article's prose specimen and keeps one intro paragraph.

### 7.3 Left out (not drawn)

- Track:
  - `invalid`, `too-many` and `failed` callouts (copy only)
  - the desktop result's delivery card on mobile (O7)
- Payment: the sandbox note.
- Blog:
  - the guides index (the label notes its differences)
  - category and tag archives (covered by the author frame plus the label)
  - the English-fallback body caption (described in the row label)
  - a separate empty desktop frame
- The header/footer placeholder pass was left out of phase C. It was done after the owner approved it; see §7.0.
