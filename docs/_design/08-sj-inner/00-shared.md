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
- O1. Order number prefix: Figma samples show `SJ-260123`; real orders are `TMB-26-00042`. Does it change with the rebrand?
- O2. Real hotline, WhatsApp number, email, support hours ("9 am – 9 pm" is unconfirmed), shop address. Until set, error and
  help copy uses no-phone wording (`track.tooMany`, three `payment.reasons`, verify help card).
- O3. Payment and courier brand names in Bangla: §7.3 says English, but the cart shows বিকাশ / নগদ and tracking shows courier
  `name_bn`. Which applies?

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
