# 08 · Account area: Smart Junior Vivid spec

**Batch:** `docs/_handoff/briefs/brief-p10-sj-inner-pages.md`, row 2 · **Written:** 2026-11-25 · **Status:** spec, no frames yet
**Figma:** file `2b6PVOOapPbcaKYIdHWZup`, page `Smart Junior — Vivid · বাংলা` (`34:5658`). Every frame sets `SJ · Color` to **Vivid**.
**Code it describes:** storefront repo `app/[locale]/account/{page,orders,orders/[number],profile,addresses,wishlist}`, `components/account/*`, `components/order/OrderParts.tsx`. Copy comes from `lib/account/messages.ts` and the `order` / `cart` keys of `lib/i18n/{en,bn}.ts`. All of these strings already exist, so no new i18n keys are needed except where this spec says **NEW**.

## 0. Patterns used on every page

**Reference frames read for this spec:** Cart title row `34:6775`, Cart mobile top bar `34:6923`, Checkout address card `34:7045`, Order-confirmed card `34:7268`, Product breadcrumb `34:6396`.

**Card.** Fill `bg/surface`, a 1 px stroke in `border/subtle`, radius `radius/xl` (28), padding `space/24` on desktop and `space/16` on mobile. This is the checkout card (`34:7045`). Page background is `bg/canvas`. There is no confetti pattern anywhere in the account area: §7.4 limits it to named sections, and these are working pages.

**Fact tile.** A pastel fill, radius `radius/lg` (20), padding `space/16`. It holds a caption (`text/muted`) above a value. This is the order-confirmed tile (`34:7274`).

**Text styles.** These are `SJ Vivid/*`, named by role like the `SJ/*` set: `Heading/H1` (Hind Siliguri Bold 40/1.29), `H2` 32, `H3` 24, `H4` 20 (Anek SemiBold), `Body/L` 16 (Anek 1.84), `Body/M` 14 (1.82), `Caption` 12 (1.61), `Label/M` 14/600, `Label/S` 12/600, `Price/M`. Digits, product names, order numbers and phone numbers run in Fredoka inside the same node (§7.2).
- **Builder:** the read tools return only the raw fonts for Vivid text, not the style names. Check each name against the local style list when you bind it.

**Buttons.** Button `5:124`:
- **Primary** (`action/primary` with `text/on-primary`) is the one main action per card.
- **Outline** M 44 or S 36 is for secondary actions.
- **Ghost** is for in-card links such as Edit or Remove.
- Mobile targets are at least 44 px. Every S 36 button sits in a 44 px hit row.

**Status pill (StagePill).** This is new: Badge `5:176` has no order-status variant (see §8, gaps). It is a pill, radius `radius/full`, padding `space/6`/`space/12`, with an icon 16 and a `Label/S` label. Each stage has both an icon and words, so the stage never depends on colour alone.

| API `stage` | Fill | Text / icon | Icon | Bangla (`order.badges`) | English |
|---|---|---|---|---|---|
| placed | `pastel/butter` | `text/on-butter` | Clock | পেয়েছি | Received |
| confirmed | `pastel/butter` | `text/on-butter` | Clock | কনফার্ম | Confirmed |
| processing | `pastel/butter` | `text/on-butter` | Package | প্যাক হচ্ছে | Being packed |
| shipped | `pastel/sky` | `pastel/sky-ink` | Truck | পথে আছে | On the way |
| delivered | `pastel/mint` | `pastel/mint-ink` | CheckCircle | ডেলিভার হয়েছে | Delivered |
| cancelled | `bg/muted` | `text/secondary` | X | বাতিল | Cancelled |
| returned | `bg/muted` | `text/secondary` | ArrowCounterClockwise | ফেরত | Returned |

**Notice (AccountNotice).** This is the one-shot result of a form post, a full-width strip with radius `radius/md` and padding `space/12`/`space/16`, holding an icon 20 and `Body/M` text in `text/primary`.
- Success: `pastel/mint` fill with a CheckCircle icon in `pastel/mint-ink`.
- Error: a danger tint with a warning icon. **Token gap:** no `bg/danger-soft`, `text/danger` or `Icon/WarningCircle` was found (§8).
- Field errors sit under the field as words, with the Input `7:178` **Error** variant.

**Empty state.** One shared block, also proposed for `00-shared.md`. It is a centred card with padding `space/32`/`space/24`, laid out as:
1. a 72 disc with a pastel fill and a 32 icon
2. an `H3` title
3. `Body/M` text in `text/secondary`, max width 360
4. a Primary M button

## 1. Account shell (`components/account/AccountShell.tsx`)

**Desktop 1440.**
1. **Header:** instance `10:289` Desktop, as used in the Vivid frames (210).
2. **Breadcrumb row:** reuse the product breadcrumb `34:6396`. It is 62 tall, at a 120 margin, in `Caption` `text/muted`:
   - হোম / আমার অ্যাকাউন্ট / {page}
   - the current page has no link
   - on the overview the trail ends at "আমার অ্যাকাউন্ট"
3. **Body:** horizontal auto layout, 1200 wide at a 120 margin, gap `space/32`, padding-bottom 80. It holds:
   - **Account menu** (280, hug height; a card with padding `space/16` and gap `space/4`):
     - **Me row** (padding `space/8`, bottom `space/16`, then a hairline in `border/subtle`): a 56 avatar circle in `pastel/sky-strong` with initials in `Fredoka` 20/600 `text/brand`, or `Icon/User` when the name is empty. Beside it, the name in `H4` (or the phone when there is no name) over the phone in `Caption` (or `ফোন যাচাই করা` / *Phone verified* when there is no name).
       - Data: `account.name`, `account.phone`, shown as `01712 345 678`.
     - **Nav items:** 48 tall, radius `radius/md`, padding `space/8`/`space/12`, gap `space/12`. Each has a 32 rounded-square icon tile (radius `radius/sm`, a pastel fill, a 18 icon in its `-ink`) and a `Label/M` label.
       - Active: fill `action/primary-soft`, label `text/primary` SemiBold, and a 3 px `action/primary` bar on the start edge (so active is not shown by colour alone; `aria-current`).
       - Hover (note only): `bg/muted`.
     - **Items, in order:**

       | Item | Bangla | English | Icon | Tile |
       |---|---|---|---|---|
       | Overview | সারসংক্ষেপ | Overview | House | `pastel/sky` |
       | Orders | আমার অর্ডার | My orders | Package | `pastel/blush` |
       | Wishlist | পছন্দের তালিকা | Wishlist | Heart | `pastel/coral` |
       | Addresses | ঠিকানা | Addresses | MapPin | `pastel/mint` |
       | Kids | আমার সন্তান | My kids | Baby | `pastel/lilac` |
       | Profile | প্রোফাইল ও সেটিংস | Profile & settings | User | `pastel/butter` |

       "My kids" links to `profile#kids` and is never the active item.
     - Below a hairline: **সাইন আউট** (*Sign out*), the same row with `Icon/SignOut` (gap; use `ArrowRight` until it exists) on a `bg/muted` tile. It is a POST form and works without JS.
   - **Content** (888, vertical, gap `space/24`): the page heading, then the page sections.
     - **Page heading:** `H1` title, then `Body/M` `text/muted` sub, gap `space/6`. This is the cart title row (`34:6775`), without its steps.
4. **Footer:** instance `41:9166` Desktop.

**Mobile 390.** There is no account menu. The account home's tiles are the menu.
- **Account home:** the mobile Header `10:289` (161) with search. Only the overview keeps the full header.
- **Inner pages:** the **mobile top bar** (`34:6923` pattern), 68 tall, padding `space/12`. It holds an Icon Button `5:161` Surface 48 with CaretLeft (aria-label = the back target), and the page title in `H4`, single line, truncating.
  - Back targets: order detail goes to "আমার অর্ডার"; every other inner page goes to "আমার অ্যাকাউন্ট".
  - The heading's sub-line moves under the top bar as `Caption` `text/muted`, padding `space/16`.
- **Body:** padding `space/16`, gap `space/16`.
- **Below the body:** the Footer `41:9166` Mobile, then the Bottom Tab Bar `10:290`, with **অ্যাকাউন্ট** (*Account*) active on every account frame.

**Signed out.** Every account route redirects to `account/login?next=…` (agent 1's spec), so there is no signed-out frame. **No JS:** every action is a plain form post, and nothing in these frames depends on JS.

## 2. Account home: `/account` (`app/[locale]/account/page.tsx`)

**Frames:** `08 · Account home — Desktop` (1440) and `— Mobile` (390).

1. **Hello card:** a card in `pastel/sky` (no stroke), radius `radius/2xl`, padding `space/24` (mobile `space/16`), horizontal, gap `space/16`.
   - Contents: a 64 avatar (mobile 56, `bg/surface` disc with initials in `text/brand`); the greeting in `H1` (mobile `H2`); a `Body/M` line.
   - Data: `account.name` → "হাই, {first name}!", or "হাই!" when there is no name. `member_since` → "{phone} · {Nov 2026} থেকে সদস্য".
   - Desktop also shows the sub "আপনার অর্ডার আর সেভ করা খেলনার সব তথ্য এখানে।"
   - *Deviation:* the code shows the card on mobile only. This spec uses it on both.
2. **Notice** (§0), only after a post.
3. **Add-name prompt:** only when `account.name` is empty. A `pastel/butter` strip, radius `radius/md`, `Body/M`, ending in a link "নাম যোগ করুন" (`text/brand`, underlined).
4. **Tiles:** a grid of 4 × 1 on desktop (210 each, gap `space/16`) and 2 × 3 on mobile (171, gap `space/16`).
   - Each tile: a pastel fill, radius `radius/xl`, padding `space/16`/`space/20`, vertical, gap `space/8`. It holds a 40 `bg/surface` disc with a 22 icon in `text/brand`, an `H4` title and a `Caption` sub. The whole tile is the link.

   | Tile | Fill | Title | Sub (data) | Shown |
   |---|---|---|---|---|
   | Orders | `pastel/blush` | অর্ডার (mobile: আমার অর্ডার) | "{n}টি পথে আছে" from `summary.active`, or "পথে কিছু নেই" | both |
   | Wishlist | `pastel/coral` | পছন্দের তালিকা | "{n}টি খেলনা" from `counts.wishlist` | both |
   | Profile | `pastel/butter` | প্রোফাইল | তথ্য ও ভাষা | mobile |
   | Addresses | `pastel/mint` | ঠিকানা | "{n}টি সেভ করা" from `counts.addresses` | both |
   | Kids | `pastel/lilac` | আমার সন্তান | "Arian 6 · Mitu 3" (child name + age) or "সন্তানের তথ্য দিন" | both |
   | Help | `pastel/sky` | সাহায্য | হোয়াটসঅ্যাপে লিখুন | mobile |

   Mobile has 6 tiles. Desktop has 4, because Profile and Help are in the menu and the footer.
5. **On its way:** the heading **পথে আছে** in `H4`, then one **Order card** (§3) at 432 wide on desktop, full width on mobile. It shows the newest active order. If there is none, a card with `Body/M` "এই মুহূর্তে কোনো অর্ডার পথে নেই।" and a link "খেলনা দেখুন".
6. **Sign out (mobile only):** a Ghost M row with an icon, after the tiles.

The mock data: a name, 1 order on the way, 4 wishlist items, 2 addresses, 2 kids.

## 3. My orders: `/account/orders` (`orders/page.tsx`, `OrderCard.tsx`)

**Frames:** `08 · Orders — Desktop` and `— Mobile`, plus `08 · Orders empty — Mobile`. The empty frame changes the layout (no grid).

1. **Heading:** "আমার অর্ডার", with the sub "{date} থেকে {n}টি অর্ডার" (the count is the sum of `summary`).
2. **Tabs:** Chip `7:146`, horizontal, gap `space/8`. Mobile scrolls sideways with the edges bleeding to the gutter.
   - Each chip is 44 tall and holds a label plus a count pill: 20 tall, `Label/S`, `bg/muted`, and `action/primary-soft` when selected. The chip set has no count slot (§8).
   - Selected = the Chip **Selected** variant with `aria-current`.
   - Tabs: চলমান *Active* · ডেলিভারি হয়েছে *Delivered* · বাতিল *Cancelled* · ফেরত *Returns*. Data: `summary.{active,delivered,cancelled,returned}`.
3. **Order cards:** a grid of 2 × n at 432 (gap `space/16`) on desktop and 1 column on mobile. Each card (§0) is vertical with gap `space/12`:
   - **Top row:** "অর্ডার SJ-260123" in `Label/M` (the link) and "{date} অর্ডার করা" in `Caption` `text/muted`, with the **StagePill** at the end.
   - **Thumbnails:** up to three 56 squares, radius `radius/sm` (from `preview[].image`, with a `pastel/blush` fill when there is no image), then a "+N" square in `bg/muted` `Label/M` (from `more_items`).
   - **Meta:** "{n}টি পণ্য · ৳2,176 · ক্যাশ অন ডেলিভারি" in `Caption` `text/secondary`, from `item_count`, `grand_total` and `payment` (`cod` gives ক্যাশ অন ডেলিভারি; `status = paid` gives পরিশোধিত).
   - **Actions:** two buttons sharing the width, S 36 inside a 44 row.
     - While the order is moving (placed, confirmed, processing, shipped): **Primary** "অর্ডার ট্র্যাক করুন" with a Truck icon, and **Outline** "সাহায্য" with WhatsappLogo.
     - Otherwise: one **Outline** "অর্ডারের বিস্তারিত" at full width.
   - Mock cards: one per stage family (shipped, processing, delivered, cancelled) so the builder shows all pills.
4. **Show more:** an Outline M button, centred, "আগের অর্ডার দেখুন" (`meta.next_cursor`; a link, not infinite scroll).
5. **Empty:** the §0 empty block, with a `pastel/blush` disc and Package icon, the title "এখানে এখনো কোনো অর্ডার নেই।" and the button "খেলনা দেখুন". The tabs stay above it, all with 0 counts.
   - *Deviation:* the code renders a plain bordered line. This spec upgrades it to the empty block.

## 4. Order detail: `/account/orders/[number]` (`orders/[number]/page.tsx`, `OrderParts`, `OrderAddressCard.tsx`)

**Frames:** `08 · Order detail — Desktop` and `— Mobile`. The mock is a **shipped COD order with a courier**: `shipment` is set, and the `picked_up` and `in_transit` milestones are reached. The other stages change only the pill, the tint and the timeline, so they get no frames.

1. **Heading:** "অর্ডার SJ-260123".
   - Desktop sub: "{date, time} অর্ডার করা · 3টি পণ্য · ৳2,176 · ক্যাশ অন ডেলিভারি".
   - Mobile: the title is in the top bar and the sub-line shows the placed date only.
2. **Body:** horizontal on desktop: main 484 (vertical, gap `space/24`) and aside 380, gap `space/24`. On mobile everything stacks in this order: status, timeline, items, address, help.
   - **Status card:** a fact-tile-style card with the stage tint from the StagePill table, radius `radius/xl`, padding `space/20`. It holds:
     - an `H3` row: icon 24 + the badge label ("পথে আছে")
     - `Body/L` text: `orders.status.shipped`, plus " রাইডারের জন্য ৳2,176 তৈরি রাখুন।" when COD, still moving and `totals.due_total > 0`
     - **(conditional, data exists)** a Primary M "এখনই পেমেন্ট করুন" (*Pay now*), only when `payment.retry_allowed` is true. It links to `/orders/{number}/payment` (agent 3). The code does not show this yet (§9).
   - **Timeline card:** a vertical stepper. This is new and shared with `track` (agent 3); dedupe it in `00-shared.md`.
     - Rows are 72 minimum, gap `space/16`. The marker is a 28 circle; a 2 px connector joins it to the next.
     - **Done:** `action/primary` fill with a white Check icon, and the connector in `action/primary`.
     - **Current:** a 28 ring in `action/primary` with a 10 core, and the label in `Label/M` with `aria-current=step`.
     - **Upcoming:** `bg/muted` and the label in `Body/M` `text/muted`.
     - **Stopped:** for cancelled or returned, a `text/secondary` disc with an X.
     - Steps (`order.stages`): অর্ডার পেয়েছি · কনফার্ম হয়েছে · প্যাক করা হয়েছে · কুরিয়ারে দেওয়া হয়েছে · পথে আছে (out for delivery) · ডেলিভার হয়েছে. Each step's time is in `Caption` "24 নভে, 3:40 অপরাহ্ণ" (`timeline[].at`, `shipment.milestones[].at`).
     - **Courier line**, under "কুরিয়ারে দেওয়া হয়েছে":
       - "Steadfast · CN-4471982" (`shipment.courier.name_bn ?? name`, `consignment_id`) in `Body/M` `text/secondary`.
       - A link "ট্র্যাক করুন" in `text/brand`, only if `tracking_url` is set.
       - Before an id exists: "Steadfast-এ বুকিং হচ্ছে" (rule 24d).
       - Then the sub-milestones as captions: "কুরিয়ার পিকআপ করেছে · {time}" and "ট্রানজিটে আছে · {time}".
   - **Items card** (aside), heading "এই অর্ডারে" in `H4`:
     - **Item rows:** a 56 image (radius `radius/sm`), the name in `Title/Card` (Bangla `name_bn` when present, else the English name), `variant_name` in a caption, "× 2", and `total` in `Price/S` at the end.
     - **Totals** (a hairline above), each with the label at the start and the value at the end:
       - মোট পণ্যমূল্য (3টি পণ্য)
       - ছাড় or কুপন (CODE), only when non-zero
       - ডেলিভারি চার্জ, showing "ফ্রি" in `text/success` when it is 0
       - **সর্বমোট** in `H4`, with the value in `Price/M`
       - Data: `totals.subtotal`, `discount_total`, `coupon_code`, `shipping_charge`, `grand_total`.
   - **Delivery address card:** a card with a 2 px `action/primary` stroke (the "chosen" look).
     - Heading: MapPin + "যেখানে পৌঁছাবে" in `Label/S` `text/brand`.
     - Lines, in order:
       - "{name} · 017•• ••• 678" (`shipping.name`, `phone_masked`) in `Label/M`
       - `address_line, area, upazila, district` in `Body/M` `text/secondary`
       - the landmark row: MapPin 16 in `text/brand` + `Label/S`. It is shown whenever `shipping.landmark` is set (rule 31).
     - Chip: `pastel/mint`, radius `radius/xs`, Truck + "ডেলিভারি ৳70" or "ডেলিভারি ফ্রি" (`totals.shipping_charge`).
   - **Help:** a Secondary L button at full width, WhatsappLogo + "সাহায্য লাগবে? হোয়াটসঅ্যাপ করুন".
3. **States:**
   - **Not found / not yours:** the API answers 404 → the locale not-found page (agent 3).
   - **Cancelled:** a `bg/muted` card, and the timeline ends with a stopped step.
   - **Delivered:** a mint card; the due line is hidden.
   - **Paid online:** the meta says পরিশোধিত.

## 5. Profile & settings: `/account/profile` (`profile/page.tsx`, `KidsCard.tsx`)

**Frames:** `08 · Profile — Desktop` and `— Mobile`, plus `08 · Profile, kids form open — Mobile`. The open form shows the add-child form open and a field error, which changes the layout.

1. **Heading:** "প্রোফাইল ও সেটিংস" (no sub).
2. **Row** (desktop: 2 × 432, gap `space/24`, top-aligned; mobile: stacked):
   - **Your details** (card, vertical, gap `space/16`), heading "আপনার তথ্য" `H4`. On mobile the heading is visually hidden.
     - **নাম** (*Name*): Input, from `name`.
     - **মোবাইল নম্বর (যাচাই করা)** (*Mobile number (verified)*): Input, read-only, with a `bg/muted` fill and a Lock icon at the end. The value comes from `phone`. Help: "এই নম্বর দিয়েই সাইন ইন করেন। বদলাতে চাইলে আমাদের ফোন করুন।"
     - **ইমেইল (ঐচ্ছিক)** (*Email (optional)*): Input, from `email`.
     - Primary M "পরিবর্তন সেভ করুন" (full width on mobile).
   - **My kids** (`id=kids`; card, vertical, gap `space/12`):
     - The heading "আমার সন্তান" in `H4`.
     - The intro "উপহার অফারসহ জন্মদিনের রিমাইন্ডারের জন্য। আমরা শুধু নাম বা ডাকনাম আর জন্মদিন রাখি — বছর না দিলেও চলে।" in `Caption`.
     - **Child tiles**, one per child, rotating through `pastel/sky`, `blush`, `mint`, `butter`, `lilac`, `coral`. Each tile: radius `radius/md`, padding `space/12`, horizontal.
       - A Baby icon 24 in `text/brand`.
       - The line "Arian · 6 বছর" in `Label/M` (`name`, `age`; "আপনার সন্তান" when there is no name).
       - "জন্মদিন 12 মার্চ" in `Caption` (`birth_day` + `monthsShort`).
       - Edit pencil 20 (§8 icon gap).
       - A Ghost 44 trash button (aria "Arian-এর তথ্য মুছুন").
       - The tile is a `<details>` summary: tapping it opens the edit form inside the tile.
     - **Outline S** "+ সন্তান যোগ করুন". At 6 children, the button is replaced by the caption "আপনি 6 জন সন্তানের তথ্য যোগ করেছেন — এর বেশি রাখা হয় না।"
     - **Child form** (open state frame): a `bg/surface` inner panel, radius `radius/md`, padding `space/12`.
       - Fields: নাম বা ডাকনাম (ঐচ্ছিক); a row of two selects, দিন* and মাস*; then জন্মের বছর (ঐচ্ছিক) with the help "শুধু বয়স দেখানোর জন্য।" and the first option "বলতে চাই না".
       - Error words under it: "জন্মদিনের দিন ও মাস বেছে নিন।"
       - Primary S "সেভ করুন".
     - Empty: "এখনো কোনো সন্তানের তথ্য যোগ করা হয়নি।" in `Body/M`, above the add button.
3. **Password** (`id=password`; card, full width):
   - The title "পাসওয়ার্ড" `H4` with the intro in `Body/M`.
   - Three inputs, 3-across on desktop and stacked on mobile: বর্তমান পাসওয়ার্ড, নতুন পাসওয়ার্ড (help "অন্তত 8টি অক্ষর।"), নতুন পাসওয়ার্ড আবার দিন.
   - The link "ভুলে গেছেন? এসএমএস কোড দিয়ে নতুন করে দিন।" in `text/brand`.
   - A row with Outline M "পাসওয়ার্ড বদলান" and the caption "বদলালে আপনার অন্য ডিভাইসগুলো থেকে সাইন আউট হয়ে যাবে।"
   - The notice appears inside this card.
4. **Notifications & language** (`id=notifications`; card, full width, rows split by `border/subtle` hairlines):
   - **Switch rows**, each at least 48 tall: a title in `Label/M` and a sub in `Caption`, with a **Switch** at the end. The Switch is a new component (§8): a 48 × 28 track, off `border/input`, on `action/primary`, with a 22 `bg/surface` knob.
     - "হোয়াটসঅ্যাপে অর্ডারের আপডেট" / "কনফার্ম, শিপ ও ডেলিভারির বার্তা". On by default.
     - "এসএমএসে আপডেট" / "একই আপডেট এসএমএসে". On.
     - `orderHow` in `Body/M`. When `whatsapp_available` is false, add the caption `whatsappUnavailable`.
   - **Birthday consent** (after a hairline): "জন্মদিনের রিমাইন্ডার ও অফার" / "প্রতিটি সন্তানের জন্মদিনের আগে · প্রত্যেকের জন্য বছরে একবার".
     - **Off by default, never pre-ticked.** The mock shows it **off**.
     - Below it: `what` (Body/M), the state caption (`on`, or "আপনি {date} তারিখে জন্মদিনের রিমাইন্ডার বন্ধ করেছেন।" from `consents.birthday.withdrawn_at`), `needsKids` when it is on with 0 children, and `stop`.
   - **Language:** "ভাষা" `Label/M`, with a segmented control at the end: a `bg/muted` track, radius `radius/full`, and two 36 segments, "English" and "বাংলা". The selected segment is `bg/surface` with e1. Data: `preferred_language`.
   - Outline M "পছন্দ সেভ করুন".
5. **Sign out (mobile only):** an Outline L button at full width, `Icon/SignOut` + "সাইন আউট".

## 6. Addresses: `/account/addresses` (`addresses/page.tsx`, `AddressCard.tsx`)

**Frames:** `08 · Addresses — Desktop` and `— Mobile`, plus `08 · Addresses empty + errors — Mobile`. That frame shows no saved addresses and a failed save with the error notice and field errors.

1. **Heading:** "ঠিকানা", with the sub "চেকআউট আর কুরিয়ার বুকিংয়ে ব্যবহার হয়".
2. **Saved cards:** 2 × 432 on desktop, 1 column on mobile, gap `space/16`. Each card (§0) is vertical with gap `space/8`.
   - **Top row:**
     - Type tag: a pill with an icon 14 and `Label/S`. Home is `pastel/blush` + House, office is `pastel/sky` + Buildings (§8: use Storefront until it exists), other is `pastel/lilac` + MapPin. The text is `label` or the type name (বাসা / অফিস / অন্যান্য).
     - On the default address only: "✓ ডিফল্ট" in `Label/S` `text/brand`.
     - Ghost S "এডিট" with the pencil, at the end.
   - "{recipient_name} · 01712 345 678" in `Label/M`.
   - The address in `Body/M` `text/secondary`: `address_line, area, upazila, district`.
   - **Landmark row,** always shown when set: MapPin 16 in `text/brand` + `Label/S` `text/primary` (rule 31, never dropped).
   - **Actions** (44 rows): "ডিফল্ট করুন" (a `text/brand` link button, non-default only) and "মুছুন" (Trash, a danger-tone Ghost; §8 gap).
   - The default card has a 2 px `action/primary` stroke. The others keep the hairline, so default is shown by the "ডিফল্ট" word too.
   - Mock: Home (default) in Chattogram with a landmark, and an Office in Dhaka with the label "নানুবাড়ি".
3. **Address form card** (desktop max 640; `id=address-form`). This is the checkout address card `34:7045` without the step number.
   - Title: "নতুন ঠিকানা যোগ করুন" `H3` (or "ঠিকানা এডিট করুন").
   - Fields, top to bottom:
     1. **পুরো নাম*** (*Full name*).
     2. **মোবাইল নম্বর*** (*Mobile number*), help "রাইডার এই নম্বরে ফোন করবেন।"
     3. A row of two selects (checkout field + CaretDown):
        - **জেলা*** (*District*), help "ডেলিভারি চার্জ এর ওপর নির্ভর করে।"
        - **উপজেলা / থানা*** (*Upazila / thana*). It is disabled with "আগে জেলা বেছে নিন" until a district is chosen; in a city district its first option is "{district} সিটি".
     4. A Secondary M button at full width, "উপজেলা দেখান". This is the no-JS refresh; it is hidden visually when JS is on, but draw it, because it is part of the no-JS layout.
     5. **এলাকা*** (*Area*), placeholder "যেমন: আগ্রাবাদ".
     6. **বাসা, রোড, ফ্ল্যাট*** (*House, road, flat*): a 2-line textarea.
     7. **ল্যান্ডমার্ক (রাইডারের সুবিধার জন্য)** (*Landmark (helps the rider)*), placeholder "যেমন: আগ্রাবাদ হোটেলের পেছনে", help "ল্যান্ডমার্ক থাকলে রাইডার দ্রুত খুঁজে পান।"
     8. **ঠিকানার ধরন** (*Address type*): three Chips `7:146` (বাসা / অফিস / অন্যান্য), radio behaviour.
     9. **ঠিকানার একটি নাম দিন (ঐচ্ছিক)** (*Name this address (optional)*), placeholder "যেমন: নানুবাড়ি".
     10. Selection Control `7:162` Checkbox "এটিকে আমার ডিফল্ট ঠিকানা করুন".
     11. Primary L "ঠিকানা সেভ করুন", plus Secondary L "বাতিল" when editing.
   - Required marker: "*" plus a visually hidden "আবশ্যক".
   - **Division** is not a field. It follows from the district, and the API stores it (`division` on the address). See §9.
4. **Empty + errors frame:**
   - The empty block (§0): `pastel/mint` disc, MapPin, "এখনো কোনো ঠিকানা সেভ করা নেই।", **no** button, because the form is right below.
   - Above it: an error notice "চিহ্নিত ঘরগুলো আবার দেখুন।"
   - The form shows Input **Error** on মোবাইল নম্বর ("013–019 দিয়ে শুরু হওয়া বাংলাদেশি মোবাইল নম্বর দিন।", the localised API message) and on এলাকা.

## 7. Wishlist: `/account/wishlist` (`wishlist/page.tsx`)

**Frames:** `08 · Wishlist — Desktop` and `— Mobile`, plus `08 · Wishlist empty — Desktop`.

1. **Heading:** "আমার পছন্দের তালিকা", with the sub "{n}টি খেলনা" (the item count).
2. **Share strip** (desktop only, when at least one item is available): horizontal, space-between. On the left, `Body/M` "**জন্মদিন আসছে?** তালিকাটা হোয়াটসঅ্যাপে পরিবারের সাথে শেয়ার করুন — সেখান থেকেই তারা কিনতে পারবেন।"; on the right, Primary S "হোয়াটসঅ্যাপে শেয়ার করুন" with WhatsappLogo.
3. **Grid:** Product Card `9:146`.
   - Desktop variant 272: 3 columns, gap `space/32`, with 36 between columns (3 × 272 + 2 × 36 = 888). Mobile variant 171: 2 columns, gap `space/16`.
   - The cards are unchanged. They show the live price, was-price and stock from the API, never the saved price.
   - Under each card: Outline S at full width, Trash + "সরান" (aria "{name} পছন্দের তালিকা থেকে সরান").
   - **Unavailable item** (`product = null` / `available = false`): a card-sized `bg/muted` box, radius `radius/xl`, holding the centred `Body/M` "আর পাওয়া যাচ্ছে না", with only the Remove button. Put one in the mock.
4. **Mobile share card** (below the grid): `pastel/butter`, radius `radius/xl`, padding `space/16`. It holds the `H4` "জন্মদিন আসছে?", the `Body/M` body, and a Primary L at full width "হোয়াটসঅ্যাপে শেয়ার করুন".
5. **Empty frame:** the empty block with a `pastel/coral` disc and Heart, "পছন্দের তালিকা খালি", "পছন্দের খেলনা হার্ট আইকনে সেভ করে রাখুন, পরে কিনতে পারবেন।" and Primary M "খেলনা দেখুন". There is no share strip.

## 8. Placement, long text, accessibility, gaps

**Placement.** Row group **08** goes on page `34:5658` below the last occupied row. Today that is Order confirmed, ending at y ≈ 25,305; agent 1's row 07 may come first, so the lead sets the final y.
- One sub-row per page, in the order of §§2–7.
- Desktop at x = 0, Mobile at x = 1840, extra state frames at x = 2340, 2840, and so on (the Category row pattern).
- 300 px between sub-rows, and a `Label — 08 · Account area` frame 150 above the first sub-row.
- Audit the bounding boxes for no intersection (rule 3).

**Frame list (15).**
- Account home: D, M
- Orders: D, M, empty M
- Order detail: D, M
- Profile: D, M, kids-form M
- Addresses: D, M, empty + errors M
- Wishlist: D, M, empty D

**Bangla long text.**
- Every label sits in hugging auto layout (Button rule §4). Titles wrap, never truncate, except the mobile top-bar title, which truncates on one line.
- Test cases: the tab "ডেলিভারি হয়েছে" plus a count at 390 (the row scrolls); the order-card buttons "অর্ডার ট্র্যাক করুন" + "সাহায্য" at 171 each (they wrap to 2 lines at most; let the button height hug); the switch sub-lines; the landmark at 2 lines.
- Bangla line-height ×1.15 comes from the `SJ Vivid/*` styles (rule 38).

**Accessibility.**
- 44 px targets on mobile (the S buttons sit in 44 rows).
- The focus ring `SJ/Focus` on every control.
- Status is never shown by colour alone: pills, the active nav item, the default address and the timeline all carry icons or words.
- Errors are in words.
- The orange `action/primary` (orange/700) with white text is 5.2:1 (§15).

**Token and component gaps (defects to fix, not work around).**
1. **Danger tokens:** no `bg/danger-soft`, `text/danger` or `border/danger` appeared in the variable reads. The Input Error variant must already bind something, so confirm it and alias these from it. They are needed for the error notice, the Remove action and field errors.
2. **Icons missing from `4:2`:** SignOut, PencilSimple, Buildings, WarningCircle, Smiley (Baby is used for kids), HeartFill (Heart is used). Fetch the paths from `@phosphor-icons/core` (Figma rule 15).
3. **No Badge "Stage" variants** (the StagePill table in §0). Add them to Badge `5:176`, or create a `Status Pill` set with 7 variants.
4. **No Switch / Toggle component.** Create `Switch` with Off/On × Default/Focus.
5. **No Select component.** The checkout draws its fields by hand (`34:7055`). Add `Input` variant `Select` with CaretDown, plus a `Disabled` state.
6. **No count slot on Chip `7:146`.** Add an optional count pill.
7. **No vertical Timeline / Stepper component.** Shared with `track` (agent 3); build it once.
8. **No Avatar** (initials circle). A small component, 40, 56 and 64.
9. **No segmented control** (language). Reuse Chip, or add a `Segmented` set.

## 9. Not designed (needs API), deviations, open questions

**Needs API (not drawn).**
- Invoice PDF download.
- Return or exchange request.
- Reorder.
- A delivery estimate on the order.
- The per-address delivery charge on the address card (needs a parcel quote).
- Back-in-stock alerts on the wishlist.
- A public wishlist "copy link".
- Rewards / loyalty tile and card (loyalty rates are owner-pending).
- "Picked for your kids" recommendations.

**Deviations from today's code, for the owner to accept.**
- (a) The hello card on desktop too.
- (b) An empty-state block on Orders, Addresses and Wishlist, replacing plain lines.
- (c) **Pay now** on the order page when `payment.retry_allowed` is true. The data exists; the code does not show it yet, and the `orders.payNow` string is already in the catalog.
- (d) Pastel icon tiles in the account menu.
- (e) The kids icon is Baby, not Smiley.

**Open questions for the owner.**
1. Should the address card also show the **division** (the API sends it; rule 31 lists it first)? The current card ends at the district.
2. For an unpaid online order, the meta line shows the raw `payment.method` code (for example "sslcommerz"). Should it read "পেমেন্ট বাকি" (*Payment pending*) instead? That needs one **NEW** key.
3. Is a confetti accent on the account hello card welcome, or does it stay pattern-free as specced (§7.4)?
4. Payment brands in Bangla: §7.3 keeps them in English, but the cart shows বিকাশ / নগদ. Which applies to the order meta line?
5. The Help buttons send customers to WhatsApp; while the WhatsApp number is the sample, they go to the contact page (`placeholder`). Confirm the real WhatsApp number.

## 10. Copy table

Keys are in `lib/account/messages.ts` (prefix `a.`) and `lib/i18n` (prefix `t.`). The nav and the stage badges are in §0 and §1. "{…}" are placeholders, and numbers stay Western.

| Key | Bangla | English |
|---|---|---|
| a.home.greeting / greetingNoName | হাই, {name}! / হাই! | Hi, {name}! / Hi there! |
| a.home.memberSince | {phone} · {date} থেকে সদস্য | {phone} · member since {date} |
| a.home.sub | আপনার অর্ডার আর সেভ করা খেলনার সব তথ্য এখানে। | Here is everything about your orders and saved toys. |
| a.home.addName / addNameLink | আপনার নাম দিন, যাতে আমরা আপনাকে নাম ধরে ডাকতে পারি। / নাম যোগ করুন | Tell us your name so we know what to call you. / Add your name |
| a.home.tiles.orders / ordersSub / ordersNone | অর্ডার / {count}টি পথে আছে / পথে কিছু নেই | Orders / {count} on the way / None on the way |
| a.home.tiles.wishlist / wishlistSub | পছন্দের তালিকা / {count}টি খেলনা | Wishlist / {count} toys |
| a.home.tiles.addresses / addressesSub | ঠিকানা / {count}টি সেভ করা | Addresses / {count} saved |
| a.home.tiles.profile / profileSub | প্রোফাইল / তথ্য ও ভাষা | Profile / Details & language |
| a.home.tiles.kids / kidsNone | আমার সন্তান / সন্তানের তথ্য দিন | My kids / Add your kids |
| a.home.tiles.help / helpSub | সাহায্য / হোয়াটসঅ্যাপে লিখুন | Help / WhatsApp us |
| a.home.onItsWay / nothingOnTheWay / browse | পথে আছে / এই মুহূর্তে কোনো অর্ডার পথে নেই। / খেলনা দেখুন | On its way / Nothing on the way right now. / Browse toys |
| a.orders.title / subSince | আমার অর্ডার / {date} থেকে {count}টি অর্ডার | My orders / {count} orders since {date} |
| a.orders.tabs.* | চলমান · ডেলিভারি হয়েছে · বাতিল · ফেরত | Active · Delivered · Cancelled · Returns |
| a.orders.empty / emptyCta | এখানে এখনো কোনো অর্ডার নেই। / খেলনা দেখুন | No orders here yet. / Browse toys |
| a.orders.number / placed | অর্ডার {number} / {date} অর্ডার করা | Order {number} / Placed {date} |
| a.orders.cod / paid | ক্যাশ অন ডেলিভারি / পরিশোধিত | Cash on delivery / Paid |
| a.orders.track / help / details | অর্ডার ট্র্যাক করুন / সাহায্য / অর্ডারের বিস্তারিত | Track order / Help / Order details |
| a.orders.loadMore / back | আগের অর্ডার দেখুন / আমার অর্ডার | Show older orders / My orders |
| a.orders.payNow / needHelp | এখনই পেমেন্ট করুন / সাহায্য লাগবে? হোয়াটসঅ্যাপ করুন | Pay now / Need help? WhatsApp us |
| a.orders.deliveryCharge / statusDue | ডেলিভারি {amount} / রাইডারের জন্য {amount} তৈরি রাখুন। | Delivery {amount} / Keep {amount} ready for the rider. |
| a.orders.status.placed | আপনার অর্ডার পেয়েছি, কনফার্ম করতে আমরা ফোন করব। | We have your order and will call you to confirm it. |
| a.orders.status.confirmed | কনফার্ম হয়েছে — খেলনাগুলো প্যাক করার জন্য তৈরি করছি। | Confirmed — we are getting your toys ready to pack. |
| a.orders.status.processing | প্যাক করা হচ্ছে। কুরিয়ারে দেওয়া হলে SMS পাঠাব। | Being packed. We will SMS you when it is handed to the courier. |
| a.orders.status.shipped | কুরিয়ারের কাছে আছে, আপনার দিকে আসছে। | With the courier and on its way to you. |
| a.orders.status.delivered | ডেলিভার হয়েছে। আশা করি বাচ্চাদের ভালো লাগবে! | Delivered. We hope the kids love it! |
| a.orders.status.cancelled / returned | এই অর্ডারটি বাতিল হয়েছে। / এই অর্ডারটি আমাদের কাছে ফেরত এসেছে। | This order was cancelled. / This order was returned to us. |
| t.order.stages.* | অর্ডার পেয়েছি · কনফার্ম হয়েছে · প্যাক করা হয়েছে · কুরিয়ারে দেওয়া হয়েছে · পথে আছে · ডেলিভার হয়েছে · বাতিল · ফেরত এসেছে | Order received · Confirmed · Packed · Handed to courier · Out for delivery · Delivered · Cancelled · Returned |
| t.order.milestones.picked_up / in_transit | কুরিয়ার পিকআপ করেছে / ট্রানজিটে আছে | Picked up by the courier / In transit |
| t.order.courierBooking / trackShort | {courier}-এ বুকিং হচ্ছে / ট্র্যাক করুন | Booking with {courier} / Track |
| t.order.itemsTitle / deliverTo | এই অর্ডারে / যেখানে পৌঁছাবে | In this order / Deliver to |
| t.cart.subtotal / discount / coupon | মোট পণ্যমূল্য ({items}) / ছাড় / কুপন ({code}) | Subtotal ({items}) / Discount / Coupon ({code}) |
| t.cart.delivery / free / total | ডেলিভারি চার্জ / ফ্রি / সর্বমোট | Delivery charge / Free / Total |
| a.profile.title / details | প্রোফাইল ও সেটিংস / আপনার তথ্য | Profile & settings / Your details |
| a.profile.name / phone / email | নাম / মোবাইল নম্বর (যাচাই করা) / ইমেইল (ঐচ্ছিক) | Name / Mobile number (verified) / Email (optional) |
| a.profile.phoneHelp | এই নম্বর দিয়েই সাইন ইন করেন। বদলাতে চাইলে আমাদের ফোন করুন। | Your phone number is how you sign in. To change it, call us. |
| a.profile.save / signOut | পরিবর্তন সেভ করুন / সাইন আউট | Save changes / Sign out |
| a.profile.password.title / intro | পাসওয়ার্ড / মোবাইল নম্বর আর এই পাসওয়ার্ড দিয়ে সাইন ইন করুন। এসএমএস কোডও সবসময় কাজ করে। | Password / Sign in with your mobile number and this password. An SMS code always works too. |
| a.profile.password.current / new / repeat | বর্তমান পাসওয়ার্ড / নতুন পাসওয়ার্ড / নতুন পাসওয়ার্ড আবার দিন | Current password / New password / Repeat new password |
| a.profile.password.newHelp / currentHelp | অন্তত 8টি অক্ষর। / ভুলে গেছেন? এসএমএস কোড দিয়ে নতুন করে দিন। | At least 8 characters. / Forgot it? Reset it with an SMS code. |
| a.profile.password.save / signsOut | পাসওয়ার্ড বদলান / বদলালে আপনার অন্য ডিভাইসগুলো থেকে সাইন আউট হয়ে যাবে। | Change password / Changing it signs you out on your other devices. |
| a.profile.kids.title / intro | আমার সন্তান / উপহার অফারসহ জন্মদিনের রিমাইন্ডারের জন্য। আমরা শুধু নাম বা ডাকনাম আর জন্মদিন রাখি — বছর না দিলেও চলে। | My kids / For birthday reminders with a gift offer. We keep only a first name or nickname and the birthday — the year is optional. |
| a.profile.kids.nameAge / birthday / unnamed | {name} · {age} বছর / জন্মদিন {date} / আপনার সন্তান | {name} · {age} years / Birthday {date} / Your child |
| a.profile.kids.add / empty / full | সন্তান যোগ করুন / এখনো কোনো সন্তানের তথ্য যোগ করা হয়নি। / আপনি {max} জন সন্তানের তথ্য যোগ করেছেন — এর বেশি রাখা হয় না। | Add a child / No children added yet. / You have added {max} children — the most we keep. |
| a.profile.kids.name / day / month / year | নাম বা ডাকনাম (ঐচ্ছিক) / দিন / মাস / জন্মের বছর (ঐচ্ছিক) | First name or nickname (optional) / Day / Month / Birth year (optional) |
| a.profile.kids.yearHelp / yearNone / choose / save | শুধু বয়স দেখানোর জন্য। / বলতে চাই না / বেছে নিন / সেভ করুন | Only to show their age. / Rather not say / Choose / Save |
| a.notices.childDateMissing | জন্মদিনের দিন ও মাস বেছে নিন। | Choose the day and month of the birthday. |
| a.profile.notifications.title | নোটিফিকেশন ও ভাষা | Notifications & language |
| …orderWhatsapp / orderWhatsappSub | হোয়াটসঅ্যাপে অর্ডারের আপডেট / কনফার্ম, শিপ ও ডেলিভারির বার্তা | Order updates on WhatsApp / Confirmed, shipped and delivered messages |
| …orderSms / orderSmsSub | এসএমএসে আপডেট / একই আপডেট এসএমএসে | SMS updates / The same updates by text message |
| …orderHow / whatsappUnavailable / orderNone | see catalog (long sentences, §5) | see catalog |
| …reminders / remindersSub | জন্মদিনের রিমাইন্ডার ও অফার / প্রতিটি সন্তানের জন্মদিনের আগে · প্রত্যেকের জন্য বছরে একবার | Birthday reminders and offers / Before each child’s birthday · once a year each |
| …what / stop / on / withdrawn / needsKids | see catalog (§5) | see catalog |
| a.profile.language / notifications.save | ভাষা / পছন্দ সেভ করুন | Language / Save preferences |
| a.addresses.title / sub / empty | ঠিকানা / চেকআউট আর কুরিয়ার বুকিংয়ে ব্যবহার হয় / এখনো কোনো ঠিকানা সেভ করা নেই। | Addresses / Used at checkout and for courier booking / No saved addresses yet. |
| a.addresses.default / edit / remove / makeDefault | ডিফল্ট / এডিট / মুছুন / ডিফল্ট করুন | Default / Edit / Remove / Make default |
| a.addresses.addTitle / editTitle | নতুন ঠিকানা যোগ করুন / ঠিকানা এডিট করুন | Add a new address / Edit address |
| a.addresses.types.* / type | বাসা · অফিস · অন্যান্য / ঠিকানার ধরন | Home · Office · Other / Address type |
| a.addresses.name / phone / phoneHelp | পুরো নাম / মোবাইল নম্বর / রাইডার এই নম্বরে ফোন করবেন। | Full name / Mobile number / The rider calls this number. |
| a.addresses.district / districtPlaceholder / districtHelp | জেলা / জেলা বেছে নিন / ডেলিভারি চার্জ এর ওপর নির্ভর করে। | District / Choose a district / Delivery charge depends on this. |
| a.addresses.upazila / upazilaPlaceholder / upazilaFirst / cityOption | উপজেলা / থানা / উপজেলা বেছে নিন / আগে জেলা বেছে নিন / {district} সিটি | Upazila / thana / Choose an upazila / Choose a district first / {district} city |
| a.addresses.showUpazilas / area / areaPlaceholder | উপজেলা দেখান / এলাকা / যেমন: আগ্রাবাদ | Show upazilas / Area / e.g. Agrabad |
| a.addresses.line / linePlaceholder | বাসা, রোড, ফ্ল্যাট / বাসা 9, রোড 4, আগ্রাবাদ সি/এ | House, road, flat / House 9, Road 4, Agrabad C/A |
| a.addresses.landmark / landmarkPlaceholder / landmarkHelp | ল্যান্ডমার্ক (রাইডারের সুবিধার জন্য) / যেমন: আগ্রাবাদ হোটেলের পেছনে / ল্যান্ডমার্ক থাকলে রাইডার দ্রুত খুঁজে পান। | Landmark (helps the rider) / e.g. Behind Agrabad Hotel / Riders find you faster with a landmark. |
| a.addresses.label / labelPlaceholder / isDefault | ঠিকানার একটি নাম দিন (ঐচ্ছিক) / যেমন: নানুবাড়ি / এটিকে আমার ডিফল্ট ঠিকানা করুন | Name this address (optional) / e.g. Grandparents / Make this my default address |
| a.addresses.save / cancel / required / errorSummary | ঠিকানা সেভ করুন / বাতিল / আবশ্যক / চিহ্নিত ঘরগুলো আবার দেখুন। | Save address / Cancel / required / Please check the highlighted fields. |
| a.notices.phoneInvalid | 013–019 দিয়ে শুরু হওয়া বাংলাদেশি মোবাইল নম্বর দিন। | Enter a Bangladeshi mobile number starting 013–019. |
| a.wishlist.title / sub | আমার পছন্দের তালিকা / {count}টি খেলনা | My wishlist / {count} toys |
| a.wishlist.emptyTitle / emptyBody / emptyCta | পছন্দের তালিকা খালি / পছন্দের খেলনা হার্ট আইকনে সেভ করে রাখুন, পরে কিনতে পারবেন। / খেলনা দেখুন | Your wishlist is empty / Tap the heart on any toy to keep it here for later. / Browse toys |
| a.wishlist.remove / removeLabel / unavailable | সরান / {name} পছন্দের তালিকা থেকে সরান / আর পাওয়া যাচ্ছে না | Remove / Remove {name} from your wishlist / No longer available |
| a.wishlist.shareTitle / shareBody / shareCta | জন্মদিন আসছে? / তালিকাটা হোয়াটসঅ্যাপে পরিবারের সাথে শেয়ার করুন — সেখান থেকেই তারা কিনতে পারবেন। / হোয়াটসঅ্যাপে শেয়ার করুন | Birthday coming up? / Share this list with family on WhatsApp — they can buy straight from it. / Share on WhatsApp |
| a.notices.saved / addressSaved / removedFromWishlist | সেভ হয়েছে। / ঠিকানা সেভ হয়েছে। / পছন্দের তালিকা থেকে সরানো হয়েছে। | Saved. / Address saved. / Removed from your wishlist. |
| **NEW** (only if Q2 is accepted) a.orders.paymentPending | পেমেন্ট বাকি | Payment pending |
