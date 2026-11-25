# 08 · 03 — Order status and system pages (Smart Junior, Vivid · বাংলা)

**Status:** spec for the Figma builder, not yet drawn · **Written:** 2026-11-25 (spec agent 3 of 5,
`docs/_handoff/briefs/brief-p10-sj-inner-pages.md`) · **Figma:** `2b6PVOOapPbcaKYIdHWZup`, page `34:5658`.

The spec covers what these pages show today, using the storefront code at `../toymartbd-storefront` and the API contract
`docs/api/checkout.md`. Every money figure, date and stage comes from the API (`StorefrontOrder`), and the client
calculates nothing. Sample values in the frames: order `TMB-26-00042` (the format `ORDER_NUMBER` accepts today, see Q1),
first name নুসরাত, phone `01712 ••• 678`, Steadfast `CN-4471982`.

**Shared conventions for all frames below**
- Each frame sets `SJ · Color` to **Vivid** explicitly, as the other frames on page `34:5658` do. Page background is `bg/canvas`.
- Desktop: 1440 wide, content 1200, side padding 120. Mobile: 390 wide, side padding `space/16`.
- Chrome instances: Header `10:289` (Desktop 1440×210, Mobile 390×161). Footer, the Vivid night-sky set `41:9166`
  (Desktop `41:8217`, Mobile `41:8832`). Bottom Tab Bar `10:290`. Buttons are `5:124`, L 52 at desktop and on mobile
  (44 px targets), full width on mobile.
- **Card** = `bg/surface`, `border/default` 1 px, `radius/xl` (28) at desktop and `radius/lg` (20) on mobile, padding
  `space/32` / `space/20`. Same language as the Order confirmed card `34:7268`.
- **Fact tile** = the Order confirmed "Order facts" tile (`34:7274`): pastel fill, `radius/lg`, padding `space/16`, label
  `SJ Vivid/Caption` `text/muted` over value `SJ Vivid/Label/L` `text/primary` (Fredoka for Latin and digits).
- **Status pill** = auto-layout H, padding `space/4`×`space/12`, `radius/full`, `SJ Vivid/Label/S`. Tones are listed per page.
- Text styles are `SJ Vivid/*` (Display/L, Heading/H1–H4, Body/L|M|S, Label/L|M|S, Caption, Overline, Price/L|M). The builder
  confirms each name against the local styles, because only `SJ Vivid/Body/M` was read back from the live frames.
- **Container fills:** every structural wrapper `fills = []` (Figma rule 1). Only cards, tiles and pills carry a fill.
- Bangla strings use `.lang-bn` line height (×1.15). Numerals are Western (rule 36).

## Placement on page `34:5658`

The existing rows end at Order confirmed (y 23512, height 1793, so the bottom is at 25305). This spec takes **one band of
five rows**, numbered **30–34** so the names do not collide with the other four specs. Rows have a 450 px gap, and each
has a `Label — NN · Name` frame 150 above it, like `34:7363`. Frames in a row sit at x = 0 (desktop) and x = 1840
(mobile), then x = 2340 and 2840 for state frames. If specs 01–02 take rows first, the lead shifts the band down as a
whole. Run the bounding-box audit after each row (rule 3).

| Row | Frames | Suggested y (if nothing else is above) |
|---|---|---|
| 30 · Track | Desktop result · Mobile result · Mobile lookup + error · Timeline specimens | 25 755 |
| 31 · Payment | Desktop failed · Desktop paid · Mobile failed · Mobile states board | 28 700 |
| 32 · Not found | Desktop · Mobile | 31 900 |
| 33 · Server error | Desktop · Mobile | 35 100 |
| 34 · Maintenance | Desktop · Mobile · Mobile "contacts confirmed" variant | 37 000 |

---

## 30 · Track (`/track` Bangla, `/en/track` English)

**Purpose:** public order status from the order number **plus** the last 4 phone digits (rule 22). No login is needed.
**Code:** `app/[locale]/track/page.tsx`, `app/api/track/route.ts` (the POST carries the digits, and a one-shot httpOnly cookie
keeps them out of the URL), `components/order/OrderParts.tsx` (OrderHeaderCard, OrderTimeline, DeliveryDetails, OrderItems,
OrderHelp) and `lib/order/format.ts` (`timelineSteps`). `components/tracking/*` is **analytics consent**, not order
tracking, and is out of scope.

### Frames
- `30 · Track — Desktop` (1440): the result state with a shipped order, the courier line and an out-for-delivery current step.
- `30 · Track — Mobile` (390): the same order.
- `30 · Track — Mobile · lookup` (390): no result yet, with the form showing the generic error. The desktop lookup state only
  swaps the main column for the intro card (below), so it gets no full frame. Draw it as a 736-wide specimen under the desktop frame.
- `30 · Track — Timeline specimens` (736 wide): the cancelled timeline, the delivered timeline and the courier line before a
  consignment exists ("Booking with Steadfast", rule 24d).

### Sections, desktop (top to bottom)
1. **Header** instance, Desktop.
2. **Title row**: auto-layout H, gap `space/12`, baseline aligned, padding top `space/32` and bottom `space/24`.
   - H1 `SJ Vivid/Heading/H1` `text/primary`: `track.title`.
   - Sub `SJ Vivid/Body/L` `text/secondary`: `track.sub`. Desktop only.
3. **Body**: auto-layout H, gap `space/24`, top aligned. Main column fills (736), rail 440 fixed. Padding bottom `space/80`.
   - **Main column** (V, gap `space/16`):
     a. **Order header card** (Card): a top row (H, space-between) holds H3 `order.number` "অর্ডার TMB-26-00042" plus the
        **status pill**. Under it, the **3 fact tiles** in a row, gap `space/12`, each filling equally:
        - `pastel/sky`: `confirmed.numberLabel` → `number`
        - `pastel/butter`: `confirmed.placedLabel` → `placed_at` as "10 নভেম্বর"
        - `pastel/mint`: COD → `confirmed.payOnDelivery` → `totals.due_total`; paid → `order.paid` → `totals.paid_total`
        The tiles replace today's caption line "Placed … · 3 items · ৳… COD". The item count moves to the items card heading (Q5).
        Status pill tones by `stage`:
        - `placed` / `confirmed` / `processing` / `shipped`: `pastel/sky` with `pastel/sky-ink`
        - `delivered`: `pastel/mint` with `pastel/mint-ink`
        - `cancelled`: **danger (token gap T1)**
        - `returned`: `bg/muted` with `text/secondary`
     b. **Timeline card** (Card, padding `space/24`). The heading "অর্ডারের অবস্থা" is screen-reader only and is not drawn.
        Vertical list of steps. Each step is H, gap `space/16`, min height 72:
        - **Marker column** (24 wide): a 24 disc, then a 2-wide rail that fills down to the next step.
          - Complete: disc `action/primary`, `Icon/Check` 14 in `icon/on-primary`, rail `action/primary`.
          - Current: disc `bg/surface` with a 2 px `action/primary` stroke and an 8 `action/primary` core.
          - Upcoming: disc `bg/muted` with a 1 px `border/default` stroke, rail `border/default`.
          - Stopped (cancelled or returned): disc danger (T1), `Icon/X` 14 in `icon/inverse`.
        - **Text column** (V, gap `space/4`, padding bottom `space/16`):
          - Label. Reached: `SJ Vivid/Label/L` `text/primary`. Current: `text/brand`, as Order confirmed's current step.
            Upcoming: `SJ Vivid/Body/M` `text/muted`.
          - Time: `SJ Vivid/Caption` `text/muted`, "11 নভেম্বর, 2:40 অপরাহ্ণ".
          - On "Handed to courier" only, the **courier line** (H wrap, gap `space/8`): "Steadfast · CN-4471982" in `SJ Vivid/Body/S`
            `text/secondary`, then the link "ট্র্যাক করুন" in `SJ Vivid/Label/S` `text/brand`. It opens the courier's own page
            (`shipment.tracking_url`). Under it, one caption per courier milestone: "কুরিয়ার পিকআপ করেছে · 11 নভেম্বর, 6:05 অপরাহ্ণ".
        Steps, in order: `placed` · `confirmed` · `processing` · `shipped` · `out` (from `out_for_delivery`) · `delivered`.
     c. **Delivery details card** (Card). H4 `order.deliveryDetails`, then 3 columns (gap `space/24`), each a label in
        `SJ Vivid/Caption` `text/muted` over values in `SJ Vivid/Body/M` `text/primary`:
        - **Deliver to:** first name only, then "area, upazila, district · 01712 ••• 678". The tracking API sends **no street,
          landmark or postcode** (checkout.md), so the frame must not draw them.
        - **Payment:** "ক্যাশ অন ডেলিভারি", then "পৌঁছালে ৳6,825 দিন"; or "অনলাইন পেমেন্ট · পরিশোধিত".
        - **Courier:** the courier line with the long link "কুরিয়ারে ট্র্যাক করুন", or `courierPending` in `text/muted`.
   - **Rail** (V, gap `space/16`):
     a. **Lookup form card** (Card, padding `space/24`). V, gap `space/16`:
        - Input `7:178` State=Default. Label `track.number`, placeholder `TMB-26-00042`, help `track.numberHelp`. The value is
          uppercase Fredoka with tabular digits.
        - Input State=Default. Label `track.phone`, placeholder `5678`, help `track.phoneHelp`. Numeric keyboard.
        - **Error callout**, only when there is an error: H, gap `space/8`, padding `space/12`, `radius/md`, fill danger-subtle (T1).
          `Icon/Info` 20 (T2: WarningCircle missing), then `SJ Vivid/Body/S` `text/primary`. One callout, in words, `role=alert`.
          The fields **never** turn red individually. That would hint which part was wrong.
        - Button Primary L, full width: `track.submit`.
     b. **Items card** (Card). H4 `order.itemsTitle`, then one row per item: 48 thumbnail (`radius/sm`, `bg/muted` when there
        is no photo), name (2 lines, `SJ Vivid/Body/S`; product names stay English), variant "· × 2" in `SJ Vivid/Caption`
        `text/muted`, and the line `total` in `SJ Vivid/Price/M`. Present only with a result.
     c. **Help card**: H, gap `space/12`, padding `space/24`, `radius/lg`, fill `pastel/mint`. A 44 disc `pastel/mint-ink` holds
        `Icon/WhatsappLogo` in `icon/inverse`, then `order.helpTitle` (`SJ Vivid/Label/L`) over `order.helpBody`
        (`SJ Vivid/Caption` `text/secondary`). While WhatsApp is a sample it links to the contact page.
4. **Footer** instance, Desktop.

**Lookup state, desktop:** the main column holds one **intro card**: Card with a `pastel/sky` fill, H, gap `space/24`. A 64 disc
`bg/surface` holds `Icon/Package` 32 in `text/brand`, beside `track.intro` (`SJ Vivid/Body/L` `text/secondary`). The rail holds
the form and the help card. No confetti: this page asks for a decision (§7.4).

### Mobile (390)
Header Mobile, then V padding `space/16` and gap `space/16`:
1. H1 `SJ Vivid/Heading/H2` `track.title`. No sub line.
2. The form card.
3. The order header card. Tiles stack 1-up (as `34:7315`), and the pill sits under the number.
4. The timeline card.
5. The items card.
6. The help card.
7. Footer Mobile and the Bottom Tab Bar.

The delivery details card is **not shown on mobile today** (`hidden lg:block`). See Q6.

### States
| State | What changes | Own frame |
|---|---|---|
| Lookup (no cookie) | Intro card in the main column (desktop). Mobile shows only form + help | Mobile lookup frame; desktop specimen |
| `not-found` (404) | Callout: "এই অর্ডার নম্বর আর ডিজিট মিলছে না…". **Identical** for an unknown number and a wrong phone, and the number stays prefilled | In the mobile lookup frame |
| `invalid` (422) | Callout `track.invalid` | Copy only |
| `too-many` (429) | Callout `track.tooMany`. Today it interpolates the hotline, see Q3 | Copy only |
| `failed` (network) | Callout `track.failed` | Copy only |
| Cancelled / returned | Timeline ends at the last reached step plus a stopped step. Pill turns danger or muted | Timeline specimen |
| Shipped, no consignment | Courier line reads "Steadfast-এ বুকিং হচ্ছে", with no Track link (rule 24d) | Timeline specimen |
| No JS | Works as is: a plain POST, a redirect, then a server render | — |
| Bangla long text | Fact tiles wrap to 2 lines (min height 78, top aligned). Timeline labels wrap inside the text column | Shown in the frames |

No loading skeleton: the page is server-rendered after the POST redirect.

---

## 31 · Payment status (`/orders/{number}/payment`)

**Purpose:** where SSLCommerz or PayStation sends the customer back (success, fail or cancel). The page re-reads the order
server-side with the placing cart's cookie and shows only what the API says. It never trusts the URL or the gateway POST.
**Code:** `app/[locale]/orders/[number]/payment/page.tsx`, `lib/payments/{methods,start,cookies}.ts`, `app/api/payments/retry`.
The page is noindex and never cached.

The view comes from the API fields, in this order:
1. `paid`: `payment.status` is `paid` or (partially) `refunded`.
2. `expired`: `stage` is `cancelled`.
3. `failed`: `retry_allowed`.
4. `pending`: `next_action`, or `status = pending`.
5. Otherwise `review`.

### Frames
- `31 · Payment — Desktop · failed` (1440): the richest state, with the retry form.
- `31 · Payment — Desktop · paid` (1440): adds the "What happens next" section.
- `31 · Payment — Mobile · failed` (390).
- `31 · Payment — Mobile · states` (390 wide board): the status header and receipt card for **pending, expired, review**, plus
  the **no-order** card, stacked with `space/48` gaps.

### Sections, desktop (top to bottom)
1. Header instance.
2. **Centred column**, 680 wide, V, gap `space/24`, padding top `space/32` and bottom `space/80`:
   a. **Status header**: V, centre aligned, gap `space/12`.
      - Halo 72 and disc 48, both `radius/full`, with a 28 icon. Tones:

        | View | Halo | Disc | Icon |
        |---|---|---|---|
        | paid | `pastel/mint-strong` | `pastel/mint-ink` | `Icon/CheckCircle` |
        | pending | `pastel/sky-strong` | `text/brand` | `Icon/Clock` |
        | failed | danger-subtle (T1) | danger (T1) | `Icon/Info` (T2) |
        | expired | `bg/muted` | `text/secondary` | `Icon/Info` |
        | review | `pastel/butter-strong` | `brand/sun` | `Icon/Info` |

        The icon is `icon/inverse` everywhere except review, which uses `text/primary` (ink on sun).
      - H1 `SJ Vivid/Heading/H1` (H2 on mobile) `text/primary`.
      - Body `SJ Vivid/Body/L` `text/secondary`, max 520.
      - **Flash callout**, only after a failed retry: the danger-subtle callout from the track page, `SJ Vivid/Label/M`.
   b. **Receipt card**: Card with `SJ/Elevation/e1`, no padding, clips its contents.
      - **Top**, padding `space/24`, H, space-between:
        - Left (V, gap `space/4`): Overline `payment.receipt` `text/muted`; Caption `amountDue` (or `amountPaid`); amount
          `SJ Vivid/Price/L` `text/primary` from `due_total` (or `paid_total`).
        - Right: status pill. Tones: paid mint, pending sky, failed danger (T1), expired muted, review `pastel/butter`
          with `text/on-butter`.
      - **Details**: a 1 px **dashed** `border/default` divider, then a 2×2 grid (gap `space/16` × `space/24`, padding `space/24`).
        Each cell is a Caption label over `SJ Vivid/Label/M` (Body/M for dates):
        - **Payment:** "কার্ড বা মোবাইল ব্যাংকিং", with "SSLCommerz-এর মাধ্যমে" below.
        - **Order number:** `TMB-26-00042`, which links to `/orders/{n}`.
        - **Date:** `placed_at`.
        - **Pay before:** `expires_at`, in danger text (T1). Shown when pending or failed. Otherwise this cell is **Total**,
          `grand_total`.
      - **Actions bar**: fill `bg/muted`, padding `space/24`, V, gap `space/12`. A row of buttons (H, gap `space/12`, each fills):
        - failed: Primary "আবার পেমেন্ট করুন · ৳6,825" (a form POST with an Idempotency-Key) and Outline "অর্ডারের বিস্তারিত দেখুন"
        - pending: Primary "পেমেন্ট পেজে যান" (only when `next_action` is a redirect), Outline "আবার দেখুন", Outline view order
        - paid and review: Primary view order
        - expired: Primary "শপে ফিরে যান" → `/toys`

        Below the buttons, centred: `Icon/ShieldCheck` 16 in `text/success` and `payment.secured` (`SJ Vivid/Caption` `text/secondary`).
        Online methods only.
   c. **What happens next** (paid only): Card, H4 `payment.nextTitle`, then 3 tiles in a row (gap `space/12`), using the
      fact-tile language:
      - `pastel/sky`, `Icon/Phone` in `text/brand`
      - `pastel/butter`, `Icon/Package` in `text/on-butter`
      - `pastel/mint`, `Icon/Truck` in `pastel/mint-ink`

      Each tile has a step number "1" (Caption `text/muted`) and its line (`SJ Vivid/Body/M`).
   d. **Items card** with totals (OrderItems `totals`). Items as on the track page, then a top `border/default` divider and rows:
      - Subtotal: "সাবটোটাল (3টি পণ্য)"
      - Discount: "কুপন SUMMER10", value in `text/sale`, minus sign. Hidden when zero.
      - Delivery: "ফ্রি" in `text/success` when zero.
      - **Total**: H4 against `SJ Vivid/Price/L`.

      Every figure is the API's.
   e. **Help card** (as on the track page).
3. Footer.

**Mobile:** Header Mobile. The column is padded `space/16`, and the halo is 72 at both sizes. Detail cells stack 1-up and the
action buttons stack full width (52). Footer Mobile and the Tab Bar follow.

### States
- **No order** (no cookie, a wrong device, or older than 30 days): a single Card, centred, max 480. H2
  `order.notFoundTitle`, Body `order.notFoundBody`, then Primary "অর্ডার ট্র্যাক করুন" → `/track?number=…` (the number
  only, never digits). It goes on the states board.
- The **sandbox** note (`payment.sandbox`) exists in the catalog but this page does not render it. It is not designed here.
- No JS: every action is a link or a form POST. There is no skeleton, because the page is server-rendered.
- Bangla long text: the retry label "আবার পেমেন্ট করুন · ৳12,450" must fit in 2 columns at 1440. Below 600 the buttons stack.

---

## 32 · Not found (`global-not-found` and `[locale]/not-found`)

**Code:** `app/global-not-found.tsx` serves unmatched URLs as a full server-rendered document. `app/[locale]/not-found.tsx`
handles a page's `notFound()` (unknown brand or category slug). Next 16.4.3 paints that one on the client, so it has no
no-JS render (README known limitation). Both render `components/status/NotFoundContent.tsx` inside PageShell, so **one
design serves both**.

### Frames
`32 · Not found — Desktop` and `— Mobile`.

### Sections
1. Header (with the category pill row, which is server data and available here).
2. **Hero card**: `radius/2xl` (36) desktop and `radius/xl` mobile, fill `pastel/sky`. Padding is `space/64` top and bottom
   with `space/64`/`space/80` sides on desktop, and `space/24` on mobile. Desktop is H (copy grows | illustration 560×300,
   gap `space/48`). Mobile is V, centred, illustration first at 300×160.
   - Eyebrow `7:80` Sky: `notFound.eyebrow`.
   - Title `SJ Vivid/Display/L` (Heading/H1 on mobile): `notFound.title`.
   - Body `SJ Vivid/Body/L` `text/secondary`.
   - Search `7:120` (Desktop 560 / Mobile 358).
   - Buttons (H, gap `space/12`; V and full width on mobile):
     - Primary L with `Icon/House`: `notFound.home`
     - Outline L with `Icon/PuzzlePiece`: `notFound.browse` → `/categories` (Q7)
   - **Illustration**: the existing "4 · ball · 4" vector (`NotFoundIllustration.tsx`), rebound to tokens:
     - the 4s: `text/brand`
     - the ball quarters: `brand/lagoon`, `brand/sun`, `brand/play`, `brand/grow`
     - hub and shine: `bg/surface`
     - floor shadow: `pastel/sky-strong`
     - dots: the brand colours
3. **Categories**: Section Header `9:213` (Desktop 1200 / Mobile 358), title `notFound.categoriesTitle`, "সব দেখুন" → `/categories`.
   Then up to 8 Category Tiles `9:159`: one row of 8 on desktop, 4-up on mobile, as on Home. The data is the first 8 of the
   category tree.
4. **Bestsellers rail**: Section Header, title `notFound.bestSellersTitle`, see-all → `/toys`. Then Product Card `9:146`:
   4 visible on desktop (the slider has up to 8) and a swipe row of 2.2 on mobile. The data is `featured`, the Home rail's source.
5. Footer, plus the Tab Bar on mobile. Section gap `space/80` desktop, `space/40` mobile.

### States
- **No categories / no products** (API down): each section hides itself, and the hero stands alone. No frame is needed.
- **Bangla long text:** the title "এই পাতাটি নেই" is short. The eyebrow "ত্রুটি 404 · পাতাটি পাওয়া যায়নি" must fit on one line at 358.

---

## 33 · Server error (`[locale]/error.tsx` and `global-error.tsx`)

**Code:** both are client error boundaries and render `components/status/ErrorShell.tsx` →
`ServerErrorContent.tsx`. `global-error` renders its own `<html>` but shows the same shell. If the error digest is the API's
`maintenance` 503, both show the maintenance notice instead (row 34, variant B). One design covers both.

**Owner rule (2026-11-15):** no footer address or hours, and no real hotline on 500 pages. A client boundary has no API
credential (rule 28), so it only ever knows the **sample** contacts. The design therefore shows **no phone anywhere on
this page**. Today's code still draws the sample number as plain text in three places: the body sentence, a "Call …" button
and the header hotline. This spec removes all three (deviation D1, a code change).

### Frames
`33 · Server error — Desktop` and `— Mobile`.

### Sections
1. **Header** instance, with two overrides:
   - **hotline slot hidden**
   - **category pill row hidden** (it needs server data)
2. **Card**, centred, 640 wide (full width on mobile), padding `space/40` desktop / `space/20` mobile, V, gap `space/16`.
   Top padding above the card is `space/64` desktop and `space/24` mobile:
   - Overline `serverError.eyebrow` "ত্রুটি 500", `text/muted`.
   - Title `SJ Vivid/Display/L` (H1 on mobile) `serverError.title`.
   - Body `SJ Vivid/Body/L` `text/secondary`: **new copy without a phone** (`serverError.bodyNoPhone`, see the copy table).
   - **Reassurance list**: fill `pastel/mint`, `radius/md`, padding `space/16`×`space/20`, V, gap `space/10`. Three rows, each
     `Icon/CheckCircle` 20 in `text/success` beside `SJ Vivid/Label/L` `text/primary`:
     - money untouched
     - cart safe
     - orders unaffected
   - Buttons (H, gap `space/12`; stacked full width on mobile):
     - Primary L: `serverError.retry`
     - Outline L with `Icon/House`: `notFound.home`. This is new. Today the only other action is the sample call button.
3. **Footer** instance with the **contact hours and address rows hidden** (override). The phone, email and WhatsApp rows
   are also hidden while they are samples, so the contact column keeps only its heading and the social buttons.
   The builder checks that the Vivid footer instance exposes these as layers that can be hidden; if not, see Q4.
4. Mobile: the Bottom Tab Bar.

No confetti and no illustration: the page stays deliberately plain.

---

## 34 · Maintenance (`/maintenance`, the API 503)

**Code:** `app/[locale]/maintenance/page.tsx` is the server page. `proxy.ts` serves it as a **503 + Retry-After** on every
URL while `STOREFRONT_MAINTENANCE=1` is set or the API reports `maintenance`. `MaintenanceNotice.tsx` is the client-boundary
copy, for when a render hits the 503 before the proxy knows. It has no contacts. The page is noindex. There is **no site
chrome**, because the shop is offline.

### Frames
- `34 · Maintenance — Desktop` and `— Mobile`: **variant B, today**. The hotline and WhatsApp are samples, so no contact is
  shown. This matches what the client boundary always shows.
- `34 · Maintenance — Mobile · contacts confirmed`: **variant A**. It shows the call and WhatsApp buttons once the owner sets
  real numbers. The frame shows the number as `placeholder`.

### Sections
Full-bleed `bg/canvas`. V, centred both ways, gap `space/40`, padding `space/96` desktop and `space/48`×`space/16` mobile.
1. **Logo** `5:51` Full Light, linking to `/`. Height 44.
2. **Card**, 600 wide, padding `space/40` / `space/20`, V, centred, gap `space/16`:
   - 64 disc `pastel/sky` with `Icon/Clock` 32 in `text/brand`.
   - Title `SJ Vivid/Display/L` (H1 on mobile): `maintenance.title`.
   - **B:** Body `maintenance.bodyNoPhone` (new copy), then the pastel/mint reassurance list (orders safe, cart safe), then
     Primary L `serverError.retry`, which reloads.
   - **A:** Body `maintenance.body` with the hotline in `SJ Vivid/Label/L`, then Primary L `Icon/Phone` "ফোন করুন {phone}" and
     Outline L `Icon/WhatsappLogo` `maintenance.whatsapp`. They sit in an H row on desktop and stack on mobile.
3. **Legal line**: `SJ Vivid/Body/S` `text/secondary`, "© 2026 Smart Junior". **No address.** Today's code appends a sample
   Dhanmondi address with `data-placeholder`. Drop it (D2).

---

## Copy table

Keys are `lib/i18n/{bn,en}.ts`. **New** marks a string the design needs that the catalog lacks.

| Key | বাংলা | English |
|---|---|---|
| track.title | অর্ডার ট্র্যাক করুন | Track your order |
| track.sub | গুদাম আর কুরিয়ার থেকে সরাসরি অবস্থা | Live status from our warehouse and courier |
| track.intro | অর্ডার নম্বর আর মোবাইল নম্বরের শেষ 4 ডিজিট দিন। লগইন লাগবে না। | Enter your order number and the last 4 digits of your phone. No login needed. |
| track.number / numberHelp | অর্ডার নম্বর / আপনার SMS আর ইনভয়েসে লেখা আছে | Order number / Printed on your SMS and invoice |
| track.phone / phoneHelp | মোবাইল নম্বরের শেষ 4 ডিজিট / দুটোই চাই, যাতে অন্য কেউ আপনার অর্ডার দেখতে না পারে | Last 4 digits of your phone / We ask for both so no one else can see your order |
| track.submit | অর্ডার দেখুন | Track order |
| track.notFound | এই অর্ডার নম্বর আর ডিজিট মিলছে না। SMS এ পাঠানো নম্বরটি আবার দেখুন। | That order number and those digits don’t match. Please check the number in your SMS. |
| track.invalid | অর্ডার নম্বর আর ফোনের শেষ 4 ডিজিট লিখুন। | Enter the order number and the last 4 digits of your phone. |
| track.tooMany | অনেকবার চেষ্টা হয়েছে — 10 মিনিট পর আবার দেখুন, বা আমাদের ফোন করুন {phone} | Too many attempts — try again in 10 minutes, or call us on {phone}. |
| track.tooManyNoPhone (new) | অনেকবার চেষ্টা হয়েছে — 10 মিনিট পর আবার দেখুন। | Too many attempts — please try again in 10 minutes. |
| track.failed | এই মুহূর্তে খুঁজে পাওয়া যায়নি। আবার চেষ্টা করুন। | We couldn’t look that up just now. Please try again. |
| order.number | অর্ডার {number} | Order {number} |
| order.confirmed.numberLabel / placedLabel | অর্ডার নম্বর / অর্ডারের তারিখ | Order number / Placed on |
| order.confirmed.payOnDelivery / paidLabel | ডেলিভারিতে পেমেন্ট / পেমেন্ট হয়েছে | Pay on delivery / Paid |
| order.timelineTitle (sr-only) | অর্ডারের অবস্থা | Order status |
| order.stages.* | অর্ডার পেয়েছি · কনফার্ম হয়েছে · প্যাক করা হয়েছে · কুরিয়ারে দেওয়া হয়েছে · পথে আছে · ডেলিভার হয়েছে · বাতিল · ফেরত এসেছে | Order received · Confirmed · Packed · Handed to courier · Out for delivery · Delivered · Cancelled · Returned |
| order.badges.* | পেয়েছি · কনফার্ম · প্যাক হচ্ছে · পথে আছে · ডেলিভার হয়েছে · বাতিল · ফেরত | Received · Confirmed · Being packed · On the way · Delivered · Cancelled · Returned |
| order.milestones.* | কুরিয়ারে বুক হয়েছে · কুরিয়ার পিকআপ করেছে · ট্রানজিটে আছে · ডেলিভারির জন্য বের হয়েছে · ডেলিভার হয়েছে · ফেরত এসেছে | Booked with the courier · Picked up by the courier · In transit · Out for delivery · Delivered · Returned |
| order.courierLine / courierBooking | {courier} · {consignment} / {courier}-এ বুকিং হচ্ছে | {courier} · {consignment} / Booking with {courier} |
| order.trackShort / trackWithCourier | ট্র্যাক করুন / কুরিয়ারে ট্র্যাক করুন | Track / Track with the courier |
| order.courierPending | প্যাক হলে কুরিয়ার ঠিক হবে | Assigned when your order is packed |
| order.deliveryDetails / deliverTo / payment / courier | ডেলিভারির তথ্য / যেখানে পৌঁছাবে / পেমেন্ট / কুরিয়ার | Delivery details / Deliver to / Payment / Courier |
| order.cod / codDue / paid | ক্যাশ অন ডেলিভারি / পৌঁছালে {amount} দিন / পরিশোধিত | Cash on delivery / Pay {amount} when it arrives / Paid |
| order.itemsTitle / qty | এই অর্ডারে / × {count} | In this order / × {count} |
| order.helpTitle | এই অর্ডার নিয়ে প্রশ্ন আছে? | Questions about this order? |
| order.helpBody (change) | হোয়াটসঅ্যাপে মেসেজ করুন | Message us on WhatsApp (hours dropped: placeholder, Q2) |
| order.track | অর্ডার ট্র্যাক করুন | Track this order |
| order.notFoundTitle | এই অর্ডারটি এখানে দেখানো যাচ্ছে না | We can’t show this order here |
| order.notFoundBody | অর্ডার কনফার্মেশন শুধু যে ডিভাইস থেকে অর্ডার করা হয়েছে সেখানেই দেখা যায়। অর্ডার নম্বর আর ফোন নম্বর দিয়ে ট্র্যাক করুন। | The confirmation is only shown on the device that placed the order. Track it with the order number and your phone instead. |
| payment.receipt / amountPaid / amountDue | পেমেন্টের বিবরণ / পরিশোধিত টাকা / বাকি টাকা | Payment details / Amount paid / Amount due |
| payment.orderLabel / dateLabel / payBy | অর্ডার নম্বর / তারিখ / এর আগে পেমেন্ট করুন | Order number / Date / Pay before |
| payment.status.* | পরিশোধিত · পেমেন্টের অপেক্ষায় · পরিশোধ হয়নি · বাতিল · যাচাই চলছে | Paid · Awaiting payment · Not paid · Cancelled · Being checked |
| payment.online.* | কার্ড বা মোবাইল ব্যাংকিং / মোবাইল ব্যাংকিং | Card or mobile banking / Mobile banking |
| payment.via / secured | {gateway}-এর মাধ্যমে / {gateway} নিরাপদে পেমেন্ট প্রক্রিয়া করে। আপনার কার্ড নম্বর বা পিন আমরা কখনো দেখি না। | via {gateway} / Processed securely by {gateway}. We never see your card number or PIN. |
| payment.successTitle / successBody | পেমেন্ট পেয়েছি — ধন্যবাদ! / অর্ডার {number}-এর জন্য {amount} পেয়েছি। ডেলিভারি নিশ্চিত করতে আমরা {phone} নম্বরে ফোন করব। | Payment received — thank you! / We have received {amount} for order {number}. We will call {phone} to confirm delivery. ({phone} = the customer’s masked number) |
| payment.pendingTitle / pendingBody | আপনার পেমেন্ট যাচাই করা হচ্ছে / এক মিনিট লাগতে পারে। একটু পরে আবার দেখুন। পেমেন্ট শেষ না করে থাকলে পেমেন্ট পেজে যান। | We are confirming your payment / This can take a minute. Check again shortly. If you didn’t finish paying, continue to the payment page. |
| payment.continuePayment / checkAgain | পেমেন্ট পেজে যান / আবার দেখুন | Continue to payment / Check again |
| payment.failedTitle / failedBody | পেমেন্ট হয়নি / অর্ডার {number}-এর জন্য কোনো টাকা কাটা হয়নি। আপনার পণ্য এখনো রাখা আছে — আবার চেষ্টা করতে পারেন। | Payment didn’t go through / No money was taken for order {number}. Your items are still held — you can try again. |
| payment.retry | আবার পেমেন্ট করুন · {total} | Try payment again · {total} |
| payment.expiredTitle / expiredBody | অর্ডারটি বাতিল হয়েছে / অর্ডার {number}-এর পেমেন্ট সময়মতো শেষ হয়নি, তাই পণ্যগুলো ছেড়ে দেওয়া হয়েছে। চাইলে আবার অর্ডার করতে পারেন। | This order was cancelled / The payment for order {number} wasn’t completed in time, so we released the items. You are welcome to order again. |
| payment.reviewTitle / reviewBody | আপনার পেমেন্ট যাচাই করা হচ্ছে / এই অর্ডারের একটি পেমেন্ট একজন কর্মীকে যাচাই করতে হবে। শিগগিরই {phone} নম্বরে ফোন করব — অনুগ্রহ করে আবার পেমেন্ট করবেন না। | We are checking your payment / A payment on this order needs a person to check it. We will call {phone} soon — please don’t pay again. |
| payment.viewOrder / shopAgain | অর্ডারের বিস্তারিত দেখুন / শপে ফিরে যান | View order details / Back to the shop |
| payment.nextTitle / next.* | এরপর কী হবে / অর্ডার নিশ্চিত করতে আমরা ফোন করব · প্যাক করে কুরিয়ারে দেওয়া হবে · আপনার দরজায় পৌঁছে যাবে | What happens next / We call you to confirm the order · We pack it and hand it to the courier · It arrives at your door |
| payment.reasons.* | (6 strings as in the catalog. Three contain the hotline `{phone}`, see Q3) | |
| notFound.eyebrow / title | ত্রুটি 404 · পাতাটি পাওয়া যায়নি / এই পাতাটি নেই | Error 404 · Page not found / This page isn't here |
| notFound.body | লিংকটি পুরনো হতে পারে, বা টাইপ করতে ভুল হয়েছে। আপনার কার্ট বা অর্ডারের কিছুই হয়নি। | The link may be old, or there may be a typo. Nothing has happened to your cart or your orders. |
| notFound.home / browse | হোম পেজে যান / সব খেলনা দেখুন | Go to the home page / Browse all toys |
| notFound.categoriesTitle / bestSellersTitle / seeAll | অথবা একটি ক্যাটাগরি থেকে শুরু করুন / এই সপ্তাহে বাচ্চাদের প্রিয় / সব দেখুন | Or start with a category / Loved by kids this week / See all |
| serverError.eyebrow / title | ত্রুটি 500 / আমাদের দিকে কিছু সমস্যা হয়েছে | Error 500 / Something broke on our side |
| serverError.bodyNoPhone (new) | এটা আমাদের ভুল, আপনার নয়। এক মিনিট পর আবার চেষ্টা করুন। | This is our fault, not yours. Please try again in a minute. |
| serverError.reassure* / reassureLabel | কোনো টাকা কাটা হয়নি · আপনার কার্ট ঠিক আছে · আগে করা অর্ডারে কোনো প্রভাব পড়েনি / যা বদলায়নি | No money was taken · Your cart is safe · Orders already placed are not affected / What has not changed |
| serverError.retry | আবার চেষ্টা করুন | Try again |
| maintenance.title | কিছুক্ষণের মধ্যেই ফিরছি | Back in a few minutes |
| maintenance.body (variant A) | সাইট আপডেট করা হচ্ছে। আগে করা অর্ডারগুলো ঠিক আছে। এখনই কিছু দরকার হলে {phone} নম্বরে ফোন করুন। | We are updating the site. Orders already placed are safe. Call {phone} if you need anything now. |
| maintenance.bodyNoPhone (new, B) | সাইট আপডেট করা হচ্ছে। একটু পরে আবার চেষ্টা করুন। | We are updating the site. Please try again shortly. |
| maintenance.call / whatsapp | ফোন করুন {phone} / হোয়াটসঅ্যাপে কথা বলুন | Call {phone} / Chat on WhatsApp |
| maintenance.legal (change) | © {year} Smart Junior | © {year} Smart Junior (address dropped, D2) |

## Token and component gaps
- **T1: no danger tokens in `SJ · Color`.** The Input Error state (`7:173`) borrows `text/sale` (sale pink). The code keeps a
  separate red `danger` ramp (§16). Add `feedback/danger`, `feedback/danger-subtle` and `text/on-danger` (Vivid: red ramp,
  AA on white). Until then, the builder binds `text/sale` / `pastel/blush` **and marks each use with a `T1` note**.
- **T2: no `Icon/WarningCircle`** in set `4:2`. Code uses it for a failed payment. Add it from `@phosphor-icons/core` (fill),
  or accept `Icon/Info`.
- **T3: no Status pill component.** Badge `5:176` has no order-stage variants. Propose a `Status Pill` set (Info · Success ·
  Danger · Neutral · Warning) on page `2:4`.
- **T4: no timeline component.** Order confirmed has a horizontal 4-step strip (`34:7283`). Tracking needs the vertical
  6-step one with Complete / Current / Upcoming / Stopped markers. Propose `Order Step` (vertical) on page `2:4`.
- `space/10` is used by the reassurance list. Confirm it is in `SJ · Space` (§1.3 lists 10, but the Foundations specimen does not).

## Deviations from today's code (each needs a code change after approval)
- **D1:** the 500 page and global-error drop the sample phone from the body, the "Call" button and the header hotline, and
  add a "Go home" button. The footer on the error shell hides the hours, address and sample contact rows.
- **D2:** the maintenance legal line drops the sample address. Variant B (no contacts) also applies to the server page
  while the hotline is a sample. Today that page draws the sample number as a non-link.
- **D3:** the track header card shows 3 fact tiles instead of the one caption line.
- **D4:** the help card drops "9 am – 9 pm".

## Open questions for the owner
1. **Order number format.** Figma's Order confirmed shows `SJ-260123`. The API, the `ORDER_NUMBER` check and the track
   placeholder use `TMB-26-00042`. Does the prefix change with the rebrand? The frames use `TMB-` until you decide.
2. **Support hours** ("9 am – 9 pm") are unconfirmed. Should they be dropped everywhere until set?
3. **Hotline in messages.** `track.tooMany` and three `payment.reasons` put the hotline in the sentence. While it is the
   sample, should they use the no-phone wording (proposed for `tooMany`, with matching variants for the reasons)?
4. **Footer on error pages.** If the Vivid footer's contact rows cannot be hidden per instance, should the 500 page use a
   slim footer (logo, legal line, social buttons) instead?
5. **Item count** moves off the track header into "এই অর্ডারে (3টি পণ্য)". Is that OK?
6. **Delivery details on mobile tracking** are hidden today. Should the mobile frame show them (payment and courier are useful there)?
7. The 404 "সব খেলনা দেখুন / Browse all toys" button opens `/categories`. Should it say "সব ক্যাটাগরি দেখুন" or go to `/toys`?
8. **Confetti on the 404 hero?** §7.4 limits the pattern to named sections. The spec leaves it off. Add it?
9. **Courier names in Bangla.** The API sends `name_bn` and the code shows it on `/bn`, but §7.3 keeps brand names in
   English. Should Steadfast and Pathao stay in English?
